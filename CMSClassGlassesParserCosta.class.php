<?php

class CMSClassGlassesParserCosta extends CMSClassGlassesParser
{
    const URL_BASE = 'http://b2b.costadelmar.com';
    const URL_BRAND = 'http://b2b.costadelmar.com/esss/category/6_1/Default.aspx';
    const URL_LOGIN_HTTPS = 'https://b2b.costadelmar.com/esss/login.aspx';

    const MATCHED_FLAG = 1;
    const IN_STOCK_VALUE = 1;
    const OUT_OF_STOCK_VALUE = 0;
    const UPC_MIN_LENGTH = 12;

    private $countVariation = 0;
    private $countVariationInStock = 0;
    private $countVariationWithUpc = 0;

    protected $http;

    /**
     * @return int
     */
    public function getProviderId()
    {
        return CMSLogicProvider::COSTA;
    }

    public function doLogin()
    {
        $this->http = $this->getHttp();

        $content = $this->doGetAndGetContents(self::URL_LOGIN_HTTPS);

        $dom = str_get_html($content);

        $viewState = current($dom->find('input[name=__VIEWSTATE]'));
        $viewStateGenerator = current($dom->find('input[name=__VIEWSTATEGENERATOR]'));

        $post = array(
            '__EVENTTARGET' => '',
            '__EVENTARGUMENT' => '',
            '__VIEWSTATE' => trim($viewState->attr['value']),
            '__VIEWSTATEGENERATOR' => trim($viewStateGenerator->attr['value']),
            '__VIEWSTATEENCRYPTED' => '',
            'ctl00$ucContentMiddleCenter$LoginControl1$inUsername' => '19118',
            'ctl00$ucContentMiddleCenter$LoginControl1$inPassword' => 'cixwmj6zok',
            'ctl00$ucContentMiddleCenter$LoginControl1$inRememberMe' => 'on',
            'ctl00$ucContentMiddleCenter$LoginControl1$btnDoLogin.x' => '46',
            'ctl00$ucContentMiddleCenter$LoginControl1$btnDoLogin.y' => '4',
        );

        $this->http->doPost(self::URL_LOGIN_HTTPS, $post);
    }

    /**
     * @param $contents
     * @return bool
     */
    public function isLoggedIn($contents)
    {
        return stripos($contents, 'Sign out') !== false;
    }

    /**
     * Обязаны переопределить абстрактный метод
     * но так как бренд всего один то в нем надобности нет
     */
    public function doSyncBrands()
    {
        echo "One static brand Costa Del Mar, code - 1.\n";
    }

    /**
     * @param string $content
     * @return array
     */
    private function getLinksOfCategoriesDom($content)
    {
        $dom = str_get_html($content);

        $linkSunCategory = $dom->find('span.CurrentCategory');
        $linkOtherCategory = $dom->find('span.OtherCategory');
        $linksDom = array_merge($linkSunCategory, $linkOtherCategory);

        return array_slice($linksDom, 0, 2);
    }

    /**
     * @param simplehtmldom $linksDom
     * @return array
     */
    private function getLinksOfCategoriesFromDom($linksDom)
    {
        $links = array();

        foreach ($linksDom as $linkDom) {
            $objectLinkDom = current($linkDom->find('a'));

            $links[] = trim($objectLinkDom->attr['href']);
        }

        return $links;
    }

    /**
     * @param simplehtmldom $itemsDom
     * @return array
     */
    private function getItemsLinksAndTitlesFromDom($itemsDom)
    {
        $items = array();

        foreach ($itemsDom as $itemDom) {
            $items[] = array(
                "title" => trim($itemDom->innertext()),
                "href" => trim($itemDom->attr['href']),
            );
        }

        return $items;
    }

    /**
     * @param string $categoryContent
     * @return array
     */
    private function getItemsLinksAndTitles($categoryContent)
    {
        $dom = str_get_html($categoryContent);
        $itemsDom = $dom->find('#Records .SingleCategoryDisplayName a');

        $items = $this->getItemsLinksAndTitlesFromDom($itemsDom);

        return $items;
    }

    /**
     * @param array $links
     * @return array
     */
    private function getItemsFromCategories($links)
    {
        $items = array();

        foreach ($links as $link) {
            $categoryContent = $this->doGetAndGetContents($link);
            $items = array_merge($items, $this->getItemsLinksAndTitles($categoryContent));
        }

        return $items;
    }

    /**
     * @throws Exception
     */
    public function doSyncItems()
    {
        $brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach ($brands as $brand) {
            if (!($brand instanceof CMSTableBrand)) {
                throw new Exception("Brand mast be an instance of CMSTableBrand!");
            }

            if ($brand->getValid()) {
                echo get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
            } else {
                echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                continue;
            }

            // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
            $this->resetModelByBrand($brand);
            // Сбрасываем сток для бренда
            $this->resetStockByBrand($brand);

            $content = $this->doGetAndGetContents(self::URL_BRAND);

            $linksDom = $this->getLinksOfCategoriesDom($content);

            $links = $this->getLinksOfCategoriesFromDom($linksDom);

            $items = $this->getItemsFromCategories($links);

            $this->syncCategoryProducts($items, $brand);
        }

        echo "\n---Count variations {$this->countVariation}\n";
        echo "---Count variations in stock {$this->countVariationInStock}\n";
        echo "---Count variations with upc {$this->countVariationWithUpc}\n";
    }

    /**
     * @param $itemsDom array simplehtmldom
     * @param $brand CMSTableBrand
     */
    private function syncCategoryProducts($items, CMSTableBrand $brand)
    {
        foreach ($items as $item) {
            $this->parseItem($item['title'], $item['href'], $brand);
        }
    }

    /**
     * Возвращает массив ссылок с названиями *подмоделей* одной модели
     * @param string $itemUrl
     * @return array
     */
    private function getSubItems($itemUrl)
    {
        $content = $this->doGetAndGetContents($itemUrl);

        $subItems = $this->getItemsLinksAndTitles($content);

        return $subItems;
    }

    /**
     * Возвращает все вариации всех *подмоделей* одной модели
     * @param array $subItems
     * @return array
     */
    private function getAllVariations($subItems)
    {
        $variations = array();

        foreach ($subItems as $subItem) {
            $itemContent = $this->doGetAndGetContents($subItem['href']);
            $variations = array_merge($variations, $this->getVariationsForOneSubItem($itemContent));
        }

        return $variations;
    }

    /**
     * Возвращает все вариации одной *подмодели*
     * @param $itemContent
     * @return array
     */
    private function getVariationsForOneSubItem($itemContent)
    {
        $dom = str_get_html($itemContent);
        $variationsDom = $dom->find('#ucContentMiddleCenter_QuickAddList1_gvitems .QuickOrderDisplayName a');

        $variations = $this->getVariationsForOneSubItemFromDom($variationsDom);

        return $variations;
    }

    /**
     * Возвращает названия и ссылки всех вариаций одной *подмодели* с дом-обьекта
     * @param simplehtmldom $variationsDom
     * @return array
     */
    private function getVariationsForOneSubItemFromDom($variationsDom)
    {
        $variations = array();

        foreach ($variationsDom as $variationDom) {
            $variations[] = array(
                'name' => trim($variationDom->innertext()),
                'href' => trim($variationDom->attr['href']),
            );
        }

        return $variations;
    }

    /**
     * Собирает данные для каждой вариации и возвращает в одном массиве
     * @param array $variationsUrls
     * @return array
     */
    private function getVariations($variationsUrls)
    {
        $variationsData = array();

        foreach ($variationsUrls as $variationsUrl) {
            $variationsData[] = $this->getVariationData($variationsUrl["href"]);
        }

        return $variationsData;
    }

    /**
     * Собирает данные одной вариации и возвращает их
     * @param string $url
     * @return array
     */
    private function getVariationData($url)
    {
        $itemContent = $this->doGetAndGetContents($url);

        $itemContentDom = str_get_html($itemContent);

        $variationData = $this->getVariationDataFromDom($itemContentDom);

        return $variationData;
    }

    /**
     * Собирает данные одной вариации из дом-обьекта и возвращает их
     * @param simplehtmldom $itemContentDom
     * @return array
     */
    private function getVariationDataFromDom($itemContentDom)
    {
        $img = $this->getImgUrlFromDom($itemContentDom);

        $price = $this->getPriceFromDom($itemContentDom);

        $color = $this->getColorFromDom($itemContentDom);

        $colorCode = $this->getColorCodeFromDom($itemContentDom);

        $stock = $this->getStockFromDom($itemContentDom);

        $upcAndSizeArray = $this->getUpcAndSize($colorCode);

        return array(
            'img' => $img,
            'price' => $price,
            'color' => $color,
            'colorCode' => $colorCode,
            'stock' => $stock,
            'upc' => $upcAndSizeArray['upc'],
            'size' => $upcAndSizeArray['size'],
        );
    }

    /**
     * @param simplehtmldom $itemContentDom
     * @return string
     */
    private function getImgUrlFromDom($itemContentDom)
    {
        $imgDom = current($itemContentDom->find("#ucContentMiddleCenter_MainImage"));
        $img = trim($imgDom->attr['src']);

        if (stripos($img, 'http') === false) {
            $img = self::URL_BASE . $img;
        }

        return $img;
    }

    /**
     * @param simplehtmldom $itemContentDom
     * @return string
     */
    private function getPriceFromDom($itemContentDom)
    {
        $priceDom = current($itemContentDom->find("#ucContentMiddleCenter_lblListPrice"));
        $price = trim($priceDom->innertext());

        $price = str_replace("$", '', $price);

        $price = round($price / 2, 2);

        return $price;
    }

    /**
     * @param simplehtmldom $itemContentDom
     * @return string
     */
    private function getColorFromDom($itemContentDom)
    {
        $colorDom = current($itemContentDom->find("#ucContentMiddleCenter_lblName"));
        $color = trim($colorDom->innertext());

        // BALLAST MATTE BLUE GRAY 580P => MATTE BLUE GRAY 580P [BALLAST - model name]
        $color = $this->deleteFirstStringWord($color);

        return $color;
    }

    /**
     * @param string $str
     * @return string
     */
    private function deleteFirstStringWord($str)
    {
        $arrayOfStrWords = explode(' ', $str);

        $deletedStrArray = $this->deleteFirstElementOfArray($arrayOfStrWords);

        $newStr = implode(' ', $deletedStrArray);

        return $newStr;
    }

    /**
     * @param array $array
     * @return array
     */
    private function deleteFirstElementOfArray($array)
    {
        reset($array);
        $key = key($array);
        unset($array[$key]);

        return $array;
    }

    /**
     * @param simplehtmldom $itemContentDom
     * @return string
     */
    private function getColorCodeFromDom($itemContentDom)
    {
        $colorCodeDom = current($itemContentDom->find("#ucContentMiddleCenter_lblSKU"));
        $colorCode = trim($colorCodeDom->innertext());

        return $colorCode;
    }

    /**
     * @param simplehtmldom $itemContentDom
     * @return int (1|0)
     */
    private function getStockFromDom($itemContentDom)
    {
        $stockDom = current($itemContentDom->find("#ucContentMiddleCenter_lblQunatityAvail"));
        preg_match("/\<br\>(\d+)\<\/span\>/", $stockDom, $matches);
        $stockFromDom = trim($matches[self::MATCHED_FLAG]);

        $stock = $stockFromDom > 0 ? self::IN_STOCK_VALUE : self::OUT_OF_STOCK_VALUE;

        return $stock;
    }

    /**
     * @param string $colorCode
     * @return bool
     * @throws CMSException
     */
    private function getUpcAndSize($colorCode)
    {
        $query = "SELECT * FROM `amz_upc` WHERE `provider_id` = :provider_id AND `model` = :colorCode";
        $q = CMSPluginDb::getInstance()->getQuery($query);
        $q->setInt('provider_id', $this->getProviderId());
        $q->setText('colorCode', $colorCode);
        $data = $q->execute();

        $upcData = $data->getData();

        if (empty($upcData)) {
            return false;
        }

        $upc = current($upcData)['upc'];
        $size = current($upcData)['size'];

        if (strlen($upc) < self::UPC_MIN_LENGTH) {
            $upc = "0" . $upc;
        }

        return array(
            'upc' => $upc,
            'size' => $size,
        );
    }

    /**
     * @param $title
     * @param $itemUrl
     * @param CMSTableBrand $brand
     */
    private function parseItem($title, $itemUrl, CMSTableBrand $brand)
    {
        $result = array();
        echo "--Parse item " . $title . " [" . $itemUrl . "]. Get sub-items ...\n";

        $subItems = $this->getSubItems($itemUrl);

        echo "---Found " . count($subItems) . " sub-items. Get all variations ...\n";

        $variationsUrls = $this->getAllVariations($subItems);

        echo "----Found " . count($variationsUrls) . " variations. Get data ...\n";

        $variationsData = $this->getVariations($variationsUrls);

        foreach ($variationsData as $variation) {
            $this->countVariation++;

            $size = isset($variation['size']) ? $variation['size'] : 0;

            if ($variation['upc']) {
                $this->countVariationWithUpc++;
            }

            if (!$variation['stock']) {
                echo "\n----------variation {$variation['color']} ({$variation['colorCode']}) not in stock. (not parse!)\n";
                echo "--------------------------------------------\n";
                continue;
            }

            $this->countVariationInStock++;

            echo "\n";
            echo "----------brand         - {$brand->getTitle()}\n";
            echo "----------model_name    - {$title}\n";
            echo "----------external_id   - {$title}\n";
            echo "----------color_title   - {$variation['color']}\n";
            echo "----------color_code    - {$variation['colorCode']}\n";
            echo "----------size 1        - {$size}\n";
            echo "----------size 2        - ~\n";
            echo "----------size 3        - ~\n";
            echo "----------image         - {$variation['img']}\n";
            echo "----------price         - {$variation['price']}\n";
            echo "----------type          - sun\n";
            echo "----------upc           - {$variation['upc']}\n";
            echo "----------stock         - {$variation['stock']}\n\n";
            echo "--------------------------------------------\n";

            // создаем обьект модели и синхронизируем
            $item = new CMSClassGlassesParserItem();
            $item->setBrand($brand);
            $item->setTitle($title);
            $item->setExternalId($title);
            $item->setType(CMSLogicGlassesItemType::getInstance()->getSun());
            $item->setColor($variation['color']);
            $item->setColorCode($variation['colorCode']);
            $item->setStockCount($variation['stock']);
            $item->setPrice($variation['price']);
            $item->setImg($variation['img']);
            $item->setSize($size);
            $item->setIsValid(1);

            if ($variation['upc']) {
                $item->setUpc($variation['upc']);
            }

            $result[] = $item;
        }

        echo "\n=============================================================================================\n";
        $this->syncingResult($result);
        echo "\n=============================================================================================\n";
    }

    /**
     * @param array $result
     */
    private function syncingResult($result)
    {
        foreach ($result as $res) {
            $res->sync();
        }
    }

    /**
     * @return mixed
     */
    private function doGetAndGetContents($url)
    {
        if (!$this->http->doGet($url)) {
            echo "Get url fail:" . $url . "\n";
        }

        $content = $this->http->getContents(false);

        return $content;
    }
}
