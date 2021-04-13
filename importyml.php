<?
define("STOP_STATISTICS", true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);
define('BX_SECURITY_SHOW_MESSAGE', true);
define("PUBLIC_AJAX_MODE", true);
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

if (!$GLOBALS['USER']->IsAdmin()) {
    LocalRedirect(SITE_DIR, true);
    return;
}

@set_time_limit(0);
@ignore_user_abort(true);

if (!CModule::IncludeModule('iblock') || !CModule::IncludeModule('catalog') || !CModule::IncludeModule('sale')) {
    throw new Exception('Не удалось подключить необходимые модули Битрикса');
    return;
}
require ($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/sale/handlers/discountpreset/simpleproduct.php");

$importYml = new ImportYml([
    'iblockCode' => 'test',
    'elementPrefix' => 'Бренд X-',
    'discountPrefix' => 'Бренд X скидка: ',
    'translitPrefix' => 'brand-x-id-',
    'action' => $_GET['action'],
    'mode' => $_GET['mode'],
    'ymlFilePath' => $_SERVER['DOCUMENT_ROOT'] . '/local/ymlfile.xml', // импортируемый файл
    'mappingFilePath' => $_SERVER['DOCUMENT_ROOT'] . '/local/section_mapping.txt',
    'picturesFilePath' => $_SERVER['DOCUMENT_ROOT'] . '/local/img/[id].jpg',
    'softDelete' => $_GET['disable_soft_delete'] != 1,
    'exceptionOnDebug' => $_GET['exception_on_debug'] == 1,
]);

$importYml->run();

class ImportYml {
    protected $iblockCode;

    protected $allUsersGroupName = 'Все пользователи (в том числе неавторизованные)';

    protected $ymlFilePath;

    protected $mappingFilePath;

    protected $picturesFilePath;

    protected $sectionSeparator = ' → ';

    protected $mappingSeparator = '=';

    protected $elementPrefix;

    protected $discountPrefix;

    protected $translitPrefix;

    protected $mode = 'debug'; // import - импортировать в инфоблок, debug - вывод отладочной информации без импорта

    protected $softDelete = true;

    protected $exceptionOnDebug = false;

    protected $count = [
        'elements' => 0,
        'discounts' => 0,
        'deleted_elements' => 0,
        'deleted_discounts' => 0,
    ];

    protected $existingElements = [];

    protected $existingPrices = [];

    protected $existingProducts = [];

    protected $existingDiscounts = [];

    protected $catalogGroupBase;

    protected $allUsersGroup;

    protected $xmlObj;

    protected $sectionsMapping = [];

    protected $outputSections = [];

    protected $inputSections = [];

    protected $elementStatus;

    protected $iblock;

    const DEFAULT_ACTION = 'importItems';

    protected $publicVariableNames = [
        'iblockCode' => ['notEmpty'],
        'allUsersGroupName' => ['notEmpty'],
        'ymlFilePath' => ['notEmpty'],
        'mappingFilePath' => ['notEmpty'],
        'picturesFilePath' => ['notEmpty'],
        'sectionSeparator' => ['notEmpty'],
        'mappingSeparator' => ['notEmpty'],
        'elementPrefix' => ['notEmpty'],
        'discountPrefix' => ['notEmpty'],
        'translitPrefix' => ['notEmpty'],
        'mode' => ['toMode'],
        'softDelete' => ['toBool'],
        'exceptionOnDebug' => ['toBool'],
        'action' => ['toAction'],
    ];

    public function __construct($values) {
        $publicVariableNamesForValidation = $this->publicVariableNames;

        foreach ($values as $key => $value) {
            $this->setValue($key, $value);
            unset($publicVariableNamesForValidation[$key]);
        }

        foreach ($publicVariableNamesForValidation as $variableName => $validators) {
            $this->validateField($variableName);
        }
    }

    public function run() {
        Bitrix\Main\Diag\Debug::startTimeLabel("run");

        $this->runAction();

        Bitrix\Main\Diag\Debug::endTimeLabel("run");

        $this->message('Выполнение заняло: ' . Bitrix\Main\Diag\Debug::getTimeLabels()['run']['time'] . ' сек.', false);
    }

    protected function runAction() {
        call_user_func([$this, $this->getNormalizedAction()]);
    }

    protected function getNormalizedAction() {
        $action = str_replace(['-', '_', ' '], '', $this->action) . 'Action';

        if (!method_exists($this, $action)) {
            $action = self::DEFAULT_ACTION . 'Action';
        }

        return $action;
    }

    protected function notEmpty($variableName) {
        if (!strlen(trim((string)$this->{$variableName}))) {
            $this->message($this->status('Значение ' . $variableName . ' не должно быть пустым', 'error'), true);
        }
    }

    protected function toBool($variableName) {
        $this->{$variableName} = (bool)$this->{$variableName};
    }

    protected function toMode($variableName) {
        $this->{$variableName} = $this->{$variableName} == 'import' ? 'import' : 'debug';
    }

    protected function toAction($variableName) {
        if (!strlen(trim((string)$this->{$variableName}))) {
            $this->{$variableName} = self::DEFAULT_ACTION;
        }
    }

    protected function setValue($variableName, $value) {
        if (array_key_exists($variableName, $this->publicVariableNames)) {
            $this->{$variableName} = $value;
            $this->validateField($variableName);
        }
    }

    protected function validateField($variableName) {
        if (array_key_exists($variableName, $this->publicVariableNames)) {
            $validators = $this->publicVariableNames[$variableName];

            foreach ($validators as $validator) {
                if (method_exists($this, $validator)) {
                    call_user_func([$this, $validator], $variableName);
                }
            }
        }
    }

    protected function importItemsAction() {
        $this->initIBlocks();

        $this->printImportBegin();
        $this->initExistingProducts();
        $this->initYmlFile();
        $this->initInputSections();
        $this->initOutputSections();
        $this->parseSectionsMappingFile();

        foreach ($this->getElementsGroupedBySections() as $arSection) {
            $this->printSectionBegin($arSection);

            foreach ($arSection['items'] as $xmlId => $arElement) {
                $this->elementStatus = [];

                if ($arElement['offersCount'] > 1) {
                    $this->elementStatus[] = ['status' => 'no action', 'text' => 'торговых предложений: ' . $arElement['offersCount']];
                }

                $xmlId = $this->getXmlId($arElement['xmlId']);
                if (isset($this->existingElements[$xmlId])) {
                    $this->existingElements[$xmlId]['ymlExists'] = true;
                }

                if ($this->mode == 'debug') {
                    $haveDiscount = false;
                    if (isset($this->existingElements[$xmlId])) {
                        $this->elementStatus[] = ['status' => 'no action', 'text' => 'есть элемент'];
                        if (isset($this->existingProducts[$xmlId])) {
                            $this->elementStatus[] = ['status' => 'no action', 'text' => 'помечен как товар'];
                        }
                        if (isset($this->existingPrices[$xmlId])) {
                            $this->elementStatus[] = ['status' => 'no action', 'text' => 'есть цена'];
                        }
                        if (isset($this->existingDiscounts[$xmlId])) {
                            $haveDiscount = true;
                            $this->elementStatus[] = ['status' => 'no action', 'text' => 'есть скидка'];
                            if ($arElement['finalPrice'] != $arElement['originalPrice']) {
                                $this->elementStatus[] = ['status' => 'no action', 'text' => 'скидка обновится'];
                            } else {
                                $this->elementStatus[] = ['status' => 'no action', 'text' => 'скидка удалится'];
                            }
                        }
                    }

                    if (!$haveDiscount) {
                        if ($arElement['finalPrice'] != $arElement['originalPrice']) {
                            $this->elementStatus[] = ['status' => 'no action', 'text' => 'будет создана скидка'];
                        }
                    }
                }

                $arFields = [
                    'NAME' => $arElement['name'],
                    'ACTIVE' => 'Y',
                    'IBLOCK_SECTION_ID' => $this->getMappingSection($this->inputSections[$arElement['sectionId']]['path']),
                    'IBLOCK_ID' => $this->iblock['id'],
                    'XML_ID' => $this->getXmlId($arElement['xmlId']),
                    'CODE' => CUtil::translit($this->translitPrefix . $arElement['xmlId'], 'ru', ['replace_space' => '-', "replace_other" => '-']),
                    'DETAIL_TEXT' => $arElement['description'],
                    'DETAIL_PICTURE' => $arElement['picturePath'] !== null ? CFile::MakeFileArray($arElement['picturePath']) : null,
                    'PROPERTY_VALUES' => [],
                ];

                $propertyValues = [];

                foreach($arElement['properties'] as $name => $values) {
                    $i = 0;
                    $propName = 'ATTR_' . strtoupper($name);

                    foreach($values as $value) {
                        $propertyValues[$propName]['n' . $i] = ['VALUE' => $value];
                        $i++;
                    }
                }

                if (!empty($propertyValues)) {
                    $arFields['PROPERTY_VALUES'] += $propertyValues;
                }

                if ($this->mode == 'import') {
                    if ($elementId = $this->writeElement($arFields)) {
                        $this->count['elements']++;
                        if ($this->writeProduct($elementId, $arFields['XML_ID'], $arElement)) {
                            if ($this->writePrice($elementId, $arFields['XML_ID'], $arElement)) {
                                $result = $this->checkDiscount($elementId, $arFields['XML_ID'], $arElement);
                                if (($result !== false) && ($result !== null) && ($result != 'could not delete')) {
                                    $this->count['discounts']++;
                                }
                            }
                        }
                    }
                } elseif ($this->mode == 'debug') {

                }
                $this->printElement($arSection, $arFields, $arElement);
            }

            $this->printSectionEnd($arSection);
        }

        $this->deleteNotExistingYmlProducts();

        $this->totals();
    }

    protected function generateMappingFileAction() {
        clearstatcache();
        if (file_exists($this->mappingFilePath) && filesize($this->mappingFilePath)) {
            $this->message($this->status('Файл <b>' . $this->mappingFilePath . '</b> не пустой', 'error'), false);
            return;
        }

        $this->initIBlocks();
        $this->initYmlFile();
        $this->initInputSections();
        $string = '';
        foreach ($this->inputSections as $arSection) {
            $string .= $arSection['path'] . ' = ' . "\n";
        }

        if (empty($this->inputSections)) {
            $this->message('В файле <b>' . $this->ymlFilePath . '<b> нет категорий', false);
        }

        $this->initOutputSections();
        $string2 = '';
        foreach ($this->outputSections as $path => $id) {
            $string2 .= $path . "\n";
        }

        if (count($this->outputSections) == 1) {
            $this->message('В инфоблоке ' . $this->iblock['name'] . ' (' . $this->iblock['id'] . ') [' . $this->iblock['type'] . '] нет разделов', false);
        } else {
            $string2 = "\n" . 'Разделы инфоблока:' . "\n" . $string2;
        }

        if (file_put_contents($this->mappingFilePath, $string . $string2) !== false) {
            $this->message($this->status('Файл назначения разделов <b>' . $this->mappingFilePath . '</b> сгенерирован', 'success'), false);
        }
    }

    protected function deleteProductsAndDiscountsAction() {
        $this->initIBlocks();

        $discountNames = [];

        $rsElements = CIBlockElement::GetList([], ['IBLOCK_ID' => $this->iblock['id'], 'XML_ID' => $this->elementPrefix . '%'], false, false, ['ID', 'NAME', 'XML_ID']);
        while ($arElement = $rsElements->Fetch()) {
            if (strpos($arElement['XML_ID'], $this->elementPrefix) === 0) {
                $discountName = $this->getDiscountName($arElement['ID'], $arElement['XML_ID'], $arElement['NAME']);
                $discountNames[$discountName] = true;

                if (CIBlockElement::Delete($arElement['ID'])) {
                    $this->message('Элемент <b>' . $arElement['NAME'] . '</b> <small>[id:' . $arElement['ID'] . ',внешний код:' . $arElement['XML_ID'] . ']</small> удален', false);
                    $this->count['deleted_elements']++;
                } else {
                    $this->message($this->status('Ошибка удаления элемента <b>' . $arElement['NAME'] . '</b> <small>[id:' . $arElement['ID'] . ',внешний код:' . $arElement['XML_ID'] . ']</small>', 'error'), false);
                }
            }
        }

        if ($this->count['deleted_elements'] == 0) {
            $this->message($this->status('Нет элементов инфоблока ' . $this->iblock['name'] . ' (' . $this->iblock['id'] . ') [' . $this->iblock['type'] . '] с префиксом "<b>' . $this->elementPrefix . '</b>" для удаления', 'no action'), false);
        } else {
            $this->message('<br>', false);
        }

        if (!empty($discountNames)) {
            $rsDiscounts = CSaleDiscount::GetList([], ['~NAME' => $this->discountPrefix . '%'], false, false, ['ID', 'NAME']);
            while ($arDiscount = $rsDiscounts->Fetch()) {
                if (strpos($arDiscount['NAME'], $this->discountPrefix) === 0) {
                    if (isset($discountNames[$arDiscount['NAME']])) {
                        if (CSaleDiscount::Delete($arDiscount['ID'])) {
                            $this->message('Скидка <b>' . $arDiscount['NAME'] . '</b> <small>[id:' . $arDiscount['ID'] . ']</small> удалена', false);
                            $this->count['deleted_discounts']++;
                        } else {
                            $this->message($this->status('Ошибка удаления скидки <b>' . $arDiscount['NAME'] . '</b> <small>[id:' . $arDiscount['ID'] . ']</small>', 'error'), false);
                        }
                    }
                }
            }
        }

        if ($this->count['deleted_discounts'] == 0) {
            $this->message($this->status('Нет скидок от удаленных товаров с префиксом "<b>' . $this->discountPrefix . '</b>" для удаления', 'no action'), false);
        }

        $this->totals();
    }

    protected function initYmlFile() {
        $this->xmlObj = simplexml_load_file($this->ymlFilePath);

        if ($this->xmlObj === false) {
            $this->message($this->message('Не удалось прочитать yml-файл: <b>' . $this->ymlFilePath . '</b>', 'error'), true);
        }
    }

    protected function message($message, $isException = null) {
        if (!is_bool($isException)) {
            if (
                (($this->mode == 'debug') && $this->exceptionOnDebug)
                || $this->mode == 'import'
            ) {
                $isException = true;
            } elseif ($this->mode == 'debug') {
                $isException = false;
            }
        }

        if ($isException === true) {
            throw new Exception(strip_tags($message));
        } elseif ($isException === false) {
            echo $message . '<br>';
        }
    }

    protected function totals() {
        $string = '<br>';

        foreach ($this->count as $key => $value) {
            if ($value > 0) {
                $string .= $key . ': ' . $value . '<br>';
            }
        }

        $this->message($string, false);
    }

    protected function getDiscountName($productId, $xmlId, $productName) {
        return $this->discountPrefix . $productName . ' [id:' . $productId . ',внешний код:' . $xmlId . ']';
    }

    protected function getXmlId($xmlId) {
        return $this->elementPrefix . $xmlId;
    }

    protected function getCurrency($productId, $currency) {
        if ($currency != 'RUB') {
            $this->message($this->status('Валюта <small>[id:' . $productId . ']</small>: ' . $currency, 'error'));
        }

        return 'RUB';
    }

    protected function unparseUrlWoQueryAndFragment($parsedUrl) {
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $user = isset($parsedUrl['user']) ? $parsedUrl['user'] : '';
        $pass = isset($parsedUrl['pass']) ? ':' . $parsedUrl['pass']  : '';
        $pass = ($user || $pass) ? "$pass@" : '';
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';

        return "$scheme$user$pass$host$port$path";
    }

    protected function formatString($string) {
        return trim(str_replace(['&quot;', '&amp;', '&gt;', '&lt;', '&apos;'], ['"', '&', '>', '<', "'"], $string));
    }

    protected function status($text, $status) {
        if ($status == 'success') {
            $color = 'green';
        } elseif ($status == 'no action') {
            $color = 'peru';
        } elseif ($status == 'error') {
            $color = 'red';
        } else {
            $color = $status;
        }

        return '<font color="' . $color . '">' . $text . '</font>';
    }

    protected function parseSectionsMappingFile() {
        if (empty($this->outputSections)) {
            $this->message($this->status('Привязка разделов: в инфоблоке ' . $this->iblock['name'] . ' (' . $this->iblock['id'] . ') [' . $this->iblock['type'] . '] нет разделов', 'no action'), false);
            return;
        }

        $handle = fopen($this->mappingFilePath, "r");
        if ($handle) {
            $lineNumber = 1;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if (strlen($line)) {
                    $instruction = explode($this->mappingSeparator, $line);
                    if (count($instruction) != 2) {
                        $this->message($this->status('Привязка разделов: строка (' . $lineNumber . ') "<b>' . $line . '</b>" некорректна', 'error'));
                    }
                    $input = rtrim($instruction[0]);
                    $output = ltrim($instruction[1]);

                    if (isset($this->outputSections[$output])) {
                        $this->sectionsMapping[$input] = $output;
                    } else {
                        $this->message($this->status('Привязка разделов: несуществующий раздел "<b>' . $output . '</b>", к которому привязан раздел "<b>' . $input . '</b>"', 'error'));
                    }
                }

                $lineNumber++;
            }

            fclose($handle);

            if ($lineNumber == 1) {
                $this->message($this->status('Привязка разделов: файл <b>' . $this->mappingFilePath . '</b> пустой', 'error'), true);
            }
        } else {
            $this->message($this->status('Привязка разделов: не удалось открыть файл <b>' . $this->mappingFilePath . '</b>', 'error'), true);
        }
    }

    protected function getMappingSection($path) {
        if ($path === null) {
            return;
        }

        $outputPath = $this->sectionsMapping[$path];
        if (isset($outputPath)) {
            $mappedId = $this->outputSections[$outputPath];
            if (isset($mappedId)) {
                return $mappedId;
            }
        }

        $this->message($this->status('Не удалось определить привязанный раздел yml-раздела <b>' . $path . '</b>', 'error'));
    }

    protected function initExistingProducts() {
        $rsGroups = CGroup::GetList(($by = 'c_sort'), ($order = 'desc'), ['NAME' => $this->allUsersGroupName]);
        if ($arGroup = $rsGroups->Fetch()) {
            $this->allUsersGroup = [$arGroup['ID']];
        } else {
            $this->message($this->status('Не определена группа пользователей "' . $this->allUsersGroupName . '", установка скидок невозможна', 'error'), true);
        }

        $productIdsToXmlIds = [];
        $discountNames = [];

        $rsElements = CIBlockElement::GetList([], ['IBLOCK_ID' => $this->iblock['id'], 'XML_ID' => $this->elementPrefix . '%'], false, false, ['ID', 'NAME', 'ACTIVE', 'XML_ID']);
        while ($arElement = $rsElements->Fetch()) {
            if (strpos($arElement['XML_ID'], $this->elementPrefix) === 0) {
                $this->existingElements[$arElement['XML_ID']] = [
                    'id' => $arElement['ID'],
                    'name' => $arElement['NAME'],
                    'xmlId' => $arElement['XML_ID'],
                    'active' => $arElement['ACTIVE'] == 'Y',
                    'ymlExists' => false,
                ];

                $productIdsToXmlIds[$arElement['ID']] = [
                    'XML_ID' => $arElement['XML_ID'],
                    'NAME' => $arElement['NAME'],
                ];

                $discountName = $this->getDiscountName($arElement['ID'], $arElement['XML_ID'], $arElement['NAME']);
                $discountNames[$discountName] = $arElement['XML_ID'];
            }
        }

        if (!empty($productIdsToXmlIds)) {
            $rsPrices = CPrice::GetList([], ['@PRODUCT_ID' => array_keys($productIdsToXmlIds)], false, false, ['ID', 'PRODUCT_ID']);
            while ($arPrice = $rsPrices->Fetch()) {
                $xmlId = $productIdsToXmlIds[$arPrice['PRODUCT_ID']]['XML_ID'];
                $this->existingPrices[$xmlId] = $arPrice['ID'];
            }

            $rsCatalogProducts = CCatalogProduct::GetList([], ['@ID' => array_keys($productIdsToXmlIds)], false, false, ['ID']);
            while ($arProduct = $rsCatalogProducts->Fetch()) {
                $xmlId = $productIdsToXmlIds[$arProduct['ID']]['XML_ID'];
                $this->existingProducts[$xmlId] = $arProduct['ID'];
            }
        }

        $this->catalogGroupBase = CCatalogGroup::GetBaseGroup()['ID'];
        if (!isset($this->catalogGroupBase)) {
            $this->message($this->status('Нет базового типа цен. Создание пометки товара и цен невозможно', 'error'), true);
        }

        $rsDiscounts = \Bitrix\Sale\Internals\DiscountTable::getList(['filter' => ['NAME' => $this->discountPrefix . '%']]);
        while ($arDiscount = $rsDiscounts->Fetch()) {
            if (strpos($arDiscount['NAME'], $this->discountPrefix) === 0) {
                $xmlId = $discountNames[$arDiscount['NAME']];
                if (!isset($xmlId)) {
                    $this->message($this->status('Скидка "<b>' . $arDiscount['NAME'] . '</b>" (' . $arDiscount['ID'] . ') не привязана ни к одному yml-товару', 'error'), false);
                    continue;
                }

                if (isset($this->existingDiscounts[$xmlId])) {
                    $this->message($this->status('Скидка "<b>' . $arDiscount['NAME'] . '</b>" (' . $arDiscount['ID'] . ') повторяется', 'error'), false);
                } else {
                    $this->existingDiscounts[$xmlId] = [
                        'ID' => $arDiscount['ID'],
                        'LID' => $arDiscount['LID'],
                        'XML_ID' => $arDiscount['XML_ID'],
                        'NAME' => $arDiscount['NAME'],
                        'CURRENCY' => $arDiscount['CURRENCY'],
                        'PRIORITY' => $arDiscount['PRIORITY'],
                        'LAST_DISCOUNT' => $arDiscount['LAST_DISCOUNT'],
                        'LAST_LEVEL_DISCOUNT' => $arDiscount['LAST_LEVEL_DISCOUNT'],
                        'USER_GROUPS' => $this->allUsersGroup,
                    ];
                }
            }
        }
    }

    protected function deleteNotExistingYmlProducts() {
        $haveElementsNotInYmlExist = false;
        $discountNames = [];

        foreach ($this->existingElements as $arElement) {
            if (!$arElement['ymlExists'] && (($this->softDelete && $arElement['active']) || !$this->softDelete)) {
                if (!$haveElementsNotInYmlExist) {
                    $this->message('<h2>Удаление несуществующих в yml-файле товаров:</h2>', false);
                }
                $haveElementsNotInYmlExist = true;

                if (!$this->softDelete) {
                    $discountNames[] = $this->getDiscountName($arElement['id'], $arElement['xmlId'], $arElement['name']);
                }

                if ($this->mode == 'debug') {
                    $this->message($this->status('Будет удален элемент <b>' . $arElement['name'] . '</b> <small>[id:' . $arElement['id'] . ',внешний код:' . $arElement['xmlId'] . ']</small>', 'no action'), false);
                } elseif ($this->mode == 'import') {
                    if ($this->softDelete) {
                        $el = new CIBlockElement;
                        $removed = $el->Update($arElement['id'], ['ACTIVE' => 'N']);
                    } else {
                        $removed = CIBlockElement::Delete($arElement['id']);
                    }

                    if ($removed) {
                        $this->message('Элемент <b>' . $arElement['name'] . '</b> <small>[id:' . $arElement['id'] . ',внешний код:' . $arElement['xmlId'] . ']</small> удален', false);
                        $this->count['deleted_elements']++;
                    } else {
                        $exceptionMessage = $GLOBALS['APPLICATION']->GetException() !== false ? $GLOBALS['APPLICATION']->GetException()->GetString() : '<i>без описания</i>';
                        $this->message($this->status('Ошибка удаления элемента <b>' . $arElement['name'] . '</b> <small>[id:' . $arElement['id'] . ',внешний код:' . $arElement['xmlId'] . ']</small>: ' . $exceptionMessage, 'error'));
                    }
                }
            }
        }

        if (!empty($discountNames)) {
            if (!$this->softDelete) {
                if ($haveElementsNotInYmlExist) {
                    $this->message('', false);
                }

                $rsDiscounts = CSaleDiscount::GetList([], ['@NAME' => $discountNames], false, false, ['ID', 'NAME']);
                while ($arDiscount = $rsDiscounts->Fetch()) {
                    if ($this->mode == 'debug') {
                        $this->message($this->status('Будет удалена скидка <b>' . $arDiscount['NAME'] . '</b> <small>[id:' . $arDiscount['ID'] . ']</small>', 'no action'), false);
                    } elseif ($this->mode == 'import') {
                        if (strpos($arDiscount['NAME'], $this->discountPrefix) === 0) {
                            if (CSaleDiscount::Delete($arDiscount['ID'])) {
                                $this->message('Скидка <b>' . $arDiscount['NAME'] . '</b> <small>[id:' . $arDiscount['ID'] . ']</small> удалена', false);
                                $this->count['deleted_discounts']++;
                            } else {
                                $this->message($this->status('Ошибка удаления скидки <b>' . $arDiscount['NAME'] . '</b> <small>[id:' . $arDiscount['ID'] . ']</small>', 'error'), false);
                            }
                        }
                    }
                }
            } else {
                $this->message('Скидки не удаляются, потому что включен режим деактивации элементов (для выключения передайте параметр disable_soft_delete=1), вместо удаления', false);
            }
        }
    }

    protected function initIBlocks() {
        if (!strlen(trim($this->iblockCode))) {
            $this->message($this->status('Код инфоблока пустой', 'error'), true);
        }

        $filterKey = is_numeric($this->iblockCode) ? 'ID' : 'CODE';

        $rsIBlocks = CIBlock::GetList([], [$filterKey => $this->iblockCode]);
        if ($arIBlock = $rsIBlocks->Fetch()) {
            $arIBlockType = CIBlockType::GetByIDLang($arIBlock['IBLOCK_TYPE_ID'], LANG_ID);
            $this->iblock = [
                'id' => $arIBlock['ID'],
                'name' => $arIBlock['NAME'],
                'type' => $arIBlockType['NAME'],
            ];
        } else {
            $this->message($this->status('Инфоблок "<b>' . $this->iblockCode . '</b>" не найден', 'error'), true);
        }
    }

    protected function initInputSections() {
        foreach ($this->xmlObj->shop->categories->category as $category) {
            $xmlId = (string)$category->attributes()['id'];

            if ($xmlId == '') {
                continue;
            }

            $parentId = (string)$category->attributes()['parentId'];

            $this->inputSections[$xmlId] = [
                'xmlId' => $xmlId,
                'name' => $this->formatString((string)$category),
                'parentId' => $parentId,
                'path' => '',
            ];
        }

        foreach ($this->inputSections as $xmlId => &$arSection) {
            $parentId = $xmlId;
            do {
                if (strlen($arSection['path'])) {
                    $arSection['path'] = $this->sectionSeparator . $arSection['path'];
                }
                $arSection['path'] = trim($this->inputSections[$parentId]['name']) . $arSection['path'];
                $parentId = $this->inputSections[$parentId]['parentId'];
            } while ($parentId != '');

            if (isset($inputSectionPaths[$arSection['path']])) {
                $this->message($this->status('yml-раздел <b>' . $arSection['path'] . '</b> повторяется', 'error'));
            } else {
                $inputSectionPaths[$arSection['path']] = true;
            }
        }
        unset($arSection);
    }

    protected function initOutputSections() {
        $rsSections = CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $this->iblock['id']], false, ['ID', 'IBLOCK_SECTION_ID', 'NAME']);

        $arSections = [];
        while ($arSection = $rsSections->Fetch()) {
            $id = $arSection['ID'];
            $arSections[$id] = $arSection;

            $parentId = $id;
            do {
                if ($arSections[$id]['path']) {
                    $arSections[$id]['path'] = $this->sectionSeparator . $arSections[$id]['path'];
                }
                $arSections[$id]['path'] = trim($arSections[$parentId]['NAME']) . $arSections[$id]['path'];
            } while ($parentId = $arSections[$parentId]['IBLOCK_SECTION_ID']);
        }

        $this->outputSections = [
            '' => false,
        ];

        foreach($arSections as $key => $arSection) {
            if (isset($this->outputSections[$arSection['path']])) {
                $this->message($this->status('Раздел каталога <b>' . $arSection['path'] . '</b> повторяется', 'error'));
            }
            $this->outputSections[$arSection['path']] = $arSection['ID'];
            unset($arSections[$key]);
        }
    }

    protected function printImportBegin() {
        $string = '';

        if ($this->mode == 'import') {
            $string .= '<h1>Импорт</h1>';
        } elseif ($this->mode == 'debug') {
            $string .= '<h1>Отладка</h1>';
        }
        $string .= '<p>';
        foreach ($this->publicVariableNames as $variableName => $validators) {
            $string .= $variableName . ': "<b>' . $this->{$variableName} . '</b>"<br>';
        }
        $string .= '</p>';
        $string .= '<p>';
        $string .= '?mode=import для импорта в инфоблок ' . $this->iblock['name'] . ' (' . $this->iblock['id'] . ') [' . $this->iblock['type'] . ']<br>';
        $string .= '?mode=debug для включения режима отладки<br>';
        $string .= '?action=generate-mapping-file для создания заглушки карты привязки разделов<br>';
        $string .= '?action=delete-products-and-discounts для удаления созданных товаров и скидок<br>';
        $string .= '?disable_soft_delete=1 для выключения режима деактивации несуществующих товаров в yml-файле. Элементы будут удаляться (также будут удаляться скидки)<br>';
        $string .= '?exception_on_debug=1 для вывода исключения при включенном режиме отладки';
        $string .= '</p>';

        $this->message($string, false);
    }

    protected function printSectionBegin($arSection) {
        $string = 'yml-раздел <b>' . $arSection['inputPath'] . '</b> <small>[yml-id:' . $arSection['xmlId'] . ']</small>';
        if ($arSection['outputPath'] !== null) {
            $string .= ' привязан к <b>' . $arSection['outputPath'] . '</b> <small>[id:' . $arSection['outputId'] . ']</small>';
        } else {
            $string .= ' не привязан в разделу';
        }
        $string .= ', содержит yml-товаров: <b>' . count($arSection['items']) . '</b></small>';

        if ($arSection['outputPath'] === null) {
            $string = $this->status('<small>' . $string . '</small>', 'DarkKhaki');
        } elseif (empty($arSection['items'])) {
            $string = $this->status('<small>' . $string . '</small>', 'grey');
        }

        $this->message($string, false);
    }

    protected function printSectionEnd($arSection) {
        if (!empty($arSection['items'])) {
            $this->message('', false);
        }
    }

    protected function printElement($arSection, $arFields, $arElement) {
        $string = '';
        foreach ($this->elementStatus as $status) {
            if (strlen($string)) {
                $string .= ', ';
            }

            $string .= $this->status($status['text'], $status['status']);
        }

        $string = $arElement['name'] . ' [yml-id:' . $arElement['xmlId'] . ',внешний код:' . $arFields['XML_ID'] . ']' . (strlen($string) ? (': ' . $string) : '');
        $string = preg_replace('#(\[[^\]]+\])#', '<small>$1</small>', $string);
        $this->message($string, false);
    }

    protected function getElementsGroupedBySections() {
        foreach ($this->xmlObj->shop->offers->offer as $offer) {
            $xmlId = (string)$offer->attributes()['id'];

            if ($xmlId == '') {
                continue;
            }

            $parsedUrl = parse_url($this->formatString($offer->url));
            $commonPart = $this->unparseUrlWoQueryAndFragment($parsedUrl);

            $arCommonParts[$commonPart][$xmlId] = $offer;
        }

        $arSections = [
            '' => [
                'xmlId' => '<i>пусто</i>',
                'inputPath' => '<i>Без раздела</i>',
                'outputPath' => '<i>Без раздела</i>',
                'outputId' => '<i>пусто</i>',
                'items' => [],
            ]
        ];

        foreach ($this->inputSections as $arSection) {
            $arSections[$arSection['xmlId']] = [
                'xmlId' => $arSection['xmlId'],
                'inputPath' => $arSection['path'],
                'outputPath' => null,
                'outputId' => null,
                'items' => [],
            ];
        }

        foreach($arCommonParts as $commonPart => $offers) {
            $minPrice = null;
            $minOfferId = null;
            $properties = [];

            foreach ($offers as $offerId => $offer) {
                $price = (double)$offer->price;

                if ($minPrice === null || $minPrice > $price) {
                    $minPrice = $price;
                    $minOfferId = $offerId;
                }

                foreach($offer->param as $param) {
                    $name = (string)$param->attributes()['name'];
                    $key = null;
                    if ($name == 'Ширина') {
                        $key = 'width';
                    } elseif ($name == 'Глубина') {
                        $key = 'depth';
                    } elseif ($name == 'Высота') {
                        $key = 'height';
                    } else {
                        $this->message($this->status('Обнаружен &lt;param&gt; с атрибутом name равным "<b>' . $name . '</b>"', 'error'));
                    }

                    if ($key !== null) {
                        $values = explode('/', (string)$param);
                        foreach ($values as $value) {
                            $properties[$key][] = $value;
                        }
                    }
                }
            }

            foreach ($properties as $name => $values) {
                $properties[$name] = array_unique($values);
            }

            $offer = $offers[$minOfferId];
            $xmlId = (string)$offer->attributes()['id'];
            $sectionXmlId = (string)$offer->categoryId;
            $filePath = str_replace('[id]', $xmlId, $this->picturesFilePath);

            $arSections[$sectionXmlId]['items'][$xmlId] = [
                'xmlId' => $xmlId,
                'name' => $this->formatString($offer->name),
                'finalPrice' => (double)$offer->price,
                'originalPrice' => isset($offer->oldprice) ? (double)$offer->oldprice : (double)$offer->price,
                'showPriceFrom' => count($offers) > 1,
                'currency' => (string)$offer->currencyId,
                'sectionId' => $sectionXmlId,
                'properties' => $properties,
                'picturePath' => file_exists($filePath) ? $filePath : null,
                'description' => $this->formatString($offer->description),
                'offersCount' => count($offers),
            ];
        }

        foreach ($arSections as &$arSection) {
            $haveItems = !empty($arSection['items']);

            if ($haveItems || isset($this->sectionsMapping[$arSection['inputPath']])) {
                $arSection['outputPath'] = $this->sectionsMapping[$arSection['inputPath']] === '' ? $arSections['']['outputPath'] : $this->sectionsMapping[$arSection['inputPath']];
                $arSection['outputId'] = $this->sectionsMapping[$arSection['inputPath']] === '' ? $arSections['']['outputId'] : $this->getMappingSection($arSection['inputPath']);
            }
        }
        unset($arSection);

        return $arSections;
    }

    protected function writeElement($arFields) {
        $el = new CIBlockElement;

        if (isset($this->existingElements[$arFields['XML_ID']])) {
            $elementId = $this->existingElements[$arFields['XML_ID']]['id'];

            if ($el->Update($elementId, $arFields, false, true, true)) {
                $this->elementStatus[] = ['status' => 'success', 'text' => 'элемент обновлен [id:' . $elementId . ']'];
                return $elementId;
            } else {
                $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка обновления элемента [id:' . $elementId . ']: ' . $el->LAST_ERROR];
                return false;
            }
        } else {
            $mixed = $el->Add($arFields, false, true, true);

            if ($mixed !== false) {
                $elementId = $mixed;
                $this->elementStatus[] = ['status' => 'success', 'text' => 'элемент создан [id:' . $elementId . ']'];
                return $elementId;
            } else {
                $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка создания: ' . $el->LAST_ERROR];
                return false;
            }
        }
    }

    protected function writeProduct($elementId, $xmlId, $arElement) {
        $arProductFields = [
            'TYPE' => Bitrix\Catalog\ProductTable::TYPE_PRODUCT,
        ];

        if (isset($this->existingProducts[$xmlId])) {
            $this->elementStatus[] = ['status' => 'no action', 'text' => 'уже помечен как товар'];
            return true;
        } else {
            $arProductFields['ID'] = $elementId;
            if (CCatalogProduct::Add($arProductFields, false)) {
                $this->elementStatus[] = ['status' => 'success', 'text' => 'помечен как товар'];
                return true;
            } else {
                $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка помечивания как товара: ' . $GLOBALS['APPLICATION']->GetException()->GetString()];
                return false;
            }
        }
    }

    protected function writePrice($productId, $xmlId, $arElement) {
        $arPriceFields = [
            'PRODUCT_ID' => $productId,
            'CATALOG_GROUP_ID' => $this->catalogGroupBase,
            'PRICE' => $arElement['originalPrice'] == $arElement['finalPrice'] ? $arElement['finalPrice'] : $arElement['originalPrice'],
            'CURRENCY' => $this->getCurrency($productId, $arElement['currency']),
            'QUANTITY_FROM' => false,
            'QUANTITY_TO' => false,
        ];

        if (isset($this->existingPrices[$xmlId])) {
            $priceId = $this->existingPrices[$xmlId];

            if (CPrice::Update($priceId, $arPriceFields)) {
                $this->elementStatus[] = ['status' => 'success', 'text' => 'цена обновлена [id:' . $priceId . ']'];
                return $priceId;
            } else {
                $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка обновления цены [id:' . $priceId . ']: ' . $GLOBALS['APPLICATION']->GetException()->GetString()];
                return false;
            }
        } else {
            $mixed = CPrice::Add($arPriceFields);

            if ($mixed !== false) {
                $priceId = $mixed;
                $this->elementStatus[] = ['status' => 'success', 'text' => 'цена создана [id:' . $priceId . ']'];
                return $priceId;
            } else {
                $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка создания цены: ' . $GLOBALS['APPLICATION']->GetException()->GetString()];
                return false;
            }
        }
    }

    protected function checkDiscount($productId, $xmlId, $arElement) {
        $arDiscount = $this->existingDiscounts[$xmlId];

        if ($arElement['finalPrice'] == $arElement['originalPrice']) {
            if (isset($arDiscount)) {
                if (CSaleDiscount::Delete($arDiscount['ID'])) {
                    $this->elementStatus[] = ['status' => 'success', 'text' => 'скидка удалена [id:' . $arDiscount['ID'] . ']'];
                    return 'deleted';
                } else {
                    $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка удаления скидки [id:' . $arDiscount['ID'] . ']'];
                    return 'could not delete';
                }
            } else {
                return;
            }
        } else {
            $discountValue = $arElement['originalPrice'] - $arElement['finalPrice'];

            $arConditions = [
                'CLASS_ID' => 'CondGroup',
                'DATA' => [
                    'All' => 'AND',
                    'True' => 'True',
                ],
                'CHILDREN' => [
                    [
                        'CLASS_ID' => 'CondBsktProductGroup',
                        'DATA' => [
                            'Found' => 'Found',
                            'All' => 'OR',
                        ],
                        'CHILDREN' => [
                            [
                                'CLASS_ID' => 'CondIBElement',
                                'DATA' => [
                                    'logic' => 'Equal',
                                    'value' => [
                                        1 => $productId,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $arActions = [
                'CLASS_ID' => 'CondGroup',
                'DATA' => [
                    'All' => 'AND',
                ],
                'CHILDREN' => [
                    [
                        'CLASS_ID' => 'ActSaleBsktGrp',
                        'DATA' => [
                            'Type' => 'Discount',
                            'Value' => $discountValue,
                            'Unit' => 'CurEach',
                            'Max' => 0,
                            'All' => 'OR',
                            'True' => 'True',
                        ],
                        'CHILDREN' => [
                            [
                                'CLASS_ID' => 'CondIBElement',
                                'DATA' => [
                                    'logic' => 'Equal',
                                    'value' => [
                                        1 => $productId,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $presetId = Sale\Handlers\DiscountPreset\SimpleProduct::class;

            if (isset($arDiscount)) {
                $discountId = $arDiscount['ID'];
                unset($arDiscount['ID']);
                $arDiscount['ACTIVE'] = 'Y';
                $arDiscount['DISCOUNT_VALUE'] = $discountValue;
                $arDiscount['DISCOUNT_TYPE'] = CSaleDiscount::OLD_DSC_TYPE_FIX;
                $arDiscount['PRESET_ID'] = $presetId;
                $arDiscount['CONDITIONS'] = $arConditions;
                $arDiscount['ACTIONS'] = $arActions;

                if ($discountId = CSaleDiscount::Update($discountId, $arDiscount)) {
                    $this->elementStatus[] = ['status' => 'success', 'text' => 'скидка обновлена [id:' . $discountId . ']'];
                    return $discountId;
                } else {
                    $exceptionMessage = $GLOBALS['APPLICATION']->GetException() !== false ? $GLOBALS['APPLICATION']->GetException()->GetString() : '<i>без описания</i>';
                    $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка обновления скидки [id:' . $discountId . ']: ' . $exceptionMessage];
                    return false;
                }
            } else {
                $arDiscount = [
                    'LID' => SITE_ID,
                    'XML_ID' => '',
                    'NAME' => $this->getDiscountName($productId, $xmlId, $arElement['name']),
                    'CURRENCY' => $this->getCurrency($productId, $arElement['currency']),
                    'DISCOUNT_VALUE' => $discountValue,
                    'DISCOUNT_TYPE' => CSaleDiscount::OLD_DSC_TYPE_FIX,
                    'ACTIVE' => 'Y',
                    'PRIORITY' => 1,
                    'LAST_DISCOUNT' => 'Y',
                    'LAST_LEVEL_DISCOUNT' => 'N',
                    'CONDITIONS' => $arConditions,
                    'ACTIONS' => $arActions,
                    'USER_GROUPS' => $this->allUsersGroup,
                    'PRESET_ID' => $presetId,
                ];

                $mixed = CSaleDiscount::Add($arDiscount);

                if ($mixed !== false) {
                    $discountId = $mixed;
                    $this->elementStatus[] = ['status' => 'success', 'text' => 'скидка создана [id:' . $discountId . ']'];
                    return $discountId;
                } else {
                    $exceptionMessage = $GLOBALS['APPLICATION']->GetException() !== false ? $GLOBALS['APPLICATION']->GetException()->GetString() : '<i>без описания</i>';
                    $this->elementStatus[] = ['status' => 'error', 'text' => 'ошибка создания скидки: ' . $exceptionMessage];
                    return false;
                }
            }

        }
    }
}
