<?php /** @noinspection PhpUndefinedClassInspection */

namespace SoluzioneSoftware\LaravelAffiliate\Networks;

use Carbon\Carbon;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use SoluzioneSoftware\LaravelAffiliate\AbstractNetwork;
use SoluzioneSoftware\LaravelAffiliate\Contracts\Network;
use SoluzioneSoftware\LaravelAffiliate\Enums\ValueType;
use SoluzioneSoftware\LaravelAffiliate\Objects\CommissionRate;
use SoluzioneSoftware\LaravelAffiliate\Objects\Product;
use SoluzioneSoftware\LaravelAffiliate\Objects\Program;
use SoluzioneSoftware\LaravelAffiliate\Objects\Transaction;

class Zanox extends AbstractNetwork implements Network
{
    /**
     * @var string
     */
    protected $baseUrl = 'https://api.zanox.com/json/2011-03-01';

    /**
     * @var string
     */
    private $connectId;

    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var string
     */
    private $adSpaceId;

    const TRACKING_CODE_PARAM = 'zpar0';

    public function __construct()
    {
        parent::__construct();

        $this->connectId = Config::get('affiliate.credentials.zanox.connect_id');
        $this->secretKey = Config::get('affiliate.credentials.zanox.secret_key');
        $this->adSpaceId = Config::get('affiliate.credentials.zanox.ad_space_id');
    }

    /**
     * @inheritDoc
     */
    public static function getMaxPerPage(): ?int
    {
        return 50;
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     */
    public function executeProductsCountRequest(
        ?array $programs = null,
        ?string $keyword = null,
        ?array $languages = null
    ): int
    {
        $result = $this->searchProducts($keyword, $programs, $languages, 1, 1);

        return (int)Arr::get($result, 'total');
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     * @throws GuzzleException
     * @see https://developer.zanox.com/web/guest/publisher-api-2011/get-products
     */
    public function executeProductsRequest(
        ?array $programs = null,
        ?string $keyword = null,
        ?array $languages = null,
        ?string $trackingCode = null,
        int $page = 1,
        int $perPage = 10
    ): Collection
    {
        $this->trackingCode = $trackingCode;

        $result = $this->searchProducts($keyword, $programs, $languages, $page, $perPage);

        $products = new Collection(Arr::get($result, 'productItems.productItem', []));

        return $products->map(function (array $productItem){
            return $this->productFromJson($productItem);
        });
    }

    /**
     * @inheritDoc
     * @see https://developer.zanox.com/web/guest/publisher-api-2011/get-products-product
     * @throws GuzzleException
     * @throws RuntimeException
     */
    public function executeGetProduct(string $id, ?string $trackingCode = null): ?Product
    {
        $this->trackingCode = $trackingCode;

        $this->requestEndPoint = "/products/product/$id";

        $response = $this->callApi();

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200){
            throw new RuntimeException("Expected response status code 200. Got $statusCode.");
        }

        $responseBody = $response->getBody();
        $product = Arr::get(json_decode($responseBody, true), 'productItem.0');
        if (is_null($product)){
            throw new RuntimeException("Got null product. Response body: $responseBody");
        }
        return $this->productFromJson($product);
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     * @throws Exception
     * @throws RuntimeException
     */
    public function executeTransactionsRequest(
        ?array $programs = null,
        ?DateTime $fromDateTime = null,
        ?DateTime $toDateTime = null,
        int $page = 1,
        int $perPage = 10
    ): Collection
    {
        // fixme: consider $page & $perPage parameters

        $leads = $this->executeReportsRequest('leads', $programs, $fromDateTime, $toDateTime);
        $sales = $this->executeReportsRequest('sales', $programs, $fromDateTime, $toDateTime);
        return $leads->merge($sales);
    }

    /**
     * @inheritDoc
     * @throws GuzzleException
     */
    public function executeCommissionRatesRequest(
        string $programId,
        int $page = 1,
        int $perPage = 100
    ): Collection
    {
        $this->requestEndPoint = "/programapplications/program/$programId/adspace/{$this->adSpaceId}/trackingcategories";

        $response = $this->callApi();

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200){
            throw new RuntimeException("Expected response status code 200. Got $statusCode.");
        }

        $responseBody = $response->getBody();
        $items = new Collection(Arr::get(json_decode($responseBody, true), 'trackingCategoryItem.trackingCategoryItem', []));

        return $items->map(function (array $trackingCategoryItem) use ($programId) {
            return $this->commissionRateFromJson($programId, $trackingCategoryItem);
        });
    }

    /**
     * @inheritDoc
     */
    public function transactionFromJson(array $transaction)
    {
        return new Transaction(
            $transaction['program']['id'],
            $transaction['id'],
            $transaction['reviewState'],
            floatval($transaction['commission']),
            $transaction['currency'],
            Carbon::parse($transaction['trackingDate']),
            $this->getTrackingCodeFromTransaction($transaction),
            $transaction
        );
    }

    /**
     * @param array $transaction
     * @return string|null
     */
    private function getTrackingCodeFromTransaction(array $transaction)
    {
        $trackingCode = null;
        array_map(
            function($value) use (&$trackingCode) {
                if ($value['@id'] === static::TRACKING_CODE_PARAM){
                    $trackingCode = $value['$'];
                }
            },
            (array)Arr::get($transaction, 'gpps', [])
        );

       return $trackingCode;
    }

    public function programFromJson(array $program)
    {
        return new Program($this, $program['@id'], $program['$']);
    }

    public function productFromJson(array $product)
    {
        return new Product(
            $this->programFromJson($product['program']),
            $product['@id'],
            $product['name'],
            $product['description'],
            Arr::get($product, 'image.large'),
            floatval($product['price']),
            $product['currency'],
            $this->getDetailsLink($product),
            $this->getTrackingLink($product),
            $product
        );
    }

    public function commissionRateFromJson(string $programId, array $commissionRate): CommissionRate
    {
        if ($commissionRate['saleFixed'] > 0){
            $type = 'fixed';
            $value = $commissionRate['saleFixed'];
        }
        else{
            $type = 'percentage';
            $value = $commissionRate['salePercent'];
        }

        return new CommissionRate(
            $programId,
            $commissionRate['@id'],
            $commissionRate['name'],
            new ValueType($type),
            (float)$value,
            $commissionRate
        );
    }

    protected function getDetailsLink(array $product)
    {
        return Arr::get($product, 'trackingLinks.trackingLink.0.ppc');
    }

    protected function getTrackingLink(array $product)
    {
        $link = $this->getDetailsLink($product);
        return $link ? $link . ($this->trackingCode ? '&' . static::TRACKING_CODE_PARAM . '=' . $this->trackingCode : '') : null;
    }

    protected function getHeaders()
    {
        $time      = $this->assignTimestamp();
        $nonce     = $this->assignNonce();
        $signature = $this->getSignature($time, $nonce);

        return array_merge(parent::getHeaders(), [
            'Authorization' => sprintf('ZXWS %s:%s', $this->connectId, $signature),
            'Date' => $time,
            'nonce' => $nonce,
        ]);
    }

    /**
     * @param  string|null  $keyword
     * @param  array|null  $programs
     * @param  array|null  $languages
     * @param  int  $page
     * @param  int  $perPage
     * @return array
     * @throws GuzzleException
     */
    private function searchProducts(
        ?string $keyword = null,
        ?array $programs = null,
        ?array $languages = null,
        int $page = 1,
        int $perPage = 10
    )
    {
        // fixme: consider $languages(region for zanox??) param
        // todo: cache results

        $this->requestEndPoint = '/products';

        if (!is_null($keyword)){
            $this->queryParams['q'] = $keyword;
        }

        if (!is_null($programs)){
            $this->queryParams['programs'] = implode(',', $programs);
        }

        $this->queryParams['page'] = $page;

        if (!is_null($perPage)){
            $this->queryParams['items'] = $perPage;
        }

        $response = $this->callApi();

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200){
            throw new RuntimeException("Expected response status code 200. Got $statusCode.");
        }

        return json_decode($response->getBody(), true);
    }

    /**
     * @param string $type possible values: leads, sales
     * @param array|null $programs
     * @param DateTime|null $fromDateTime
     * @param DateTime|null $toDateTime
     * @return Collection
     * @throws GuzzleException
     * @throws Exception
     * @see https://developer.zanox.com/web/guest/publisher-api-2011/get-leads-date
     * @see https://developer.zanox.com/web/guest/publisher-api-2011/get-sales-date
     */
    private function executeReportsRequest(
        string $type,
        ?array $programs = null,
        ?DateTime $fromDateTime = null,
        ?DateTime $toDateTime = null
    )
    {
        $fromDateTime = (is_null($fromDateTime) ? Date::now() : new Carbon($fromDateTime))->startOfDay();
        $toDateTime = (is_null($toDateTime) ? Date::now() : new Carbon($toDateTime))->startOfDay();

        $this->queryParams = [];
        if (!is_null($programs)){
            $this->queryParams['programs'] = implode(',', $programs);
        }

        $transactions = new Collection();
        while ($fromDateTime->lessThanOrEqualTo($toDateTime)){
            $this->requestEndPoint = "/reports/{$type}/date/{$fromDateTime->format('Y-m-d')}";
            $response = $this->callApi();
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200){
                throw new RuntimeException("Expected response status code 200. Got $statusCode.");
            }

            $json = json_decode($response->getBody(), true);
            if ((int)Arr::get($json, 'items', 0) === 0){
                return $transactions;
            }

            foreach ($json["{$type}Items"] as $item) {
                $transactions->push($this->transactionFromJson($item));
            }

            $fromDateTime->addDay();
        }
        return $transactions;
    }

    /**
     * Returns a HMAC based signature
     *
     * @param string $timestamp Timestamp - in GMT, format "EEE, dd MMM yyyy HH:mm:ss"
     * @param string $nonce unique random string, generated at the time of request, valid once, 20 or more
     * @return string
     */
    private function getSignature($timestamp, $nonce)
    {
        $sign = 'GET' . $this->requestEndPoint . $timestamp . $nonce; // fixme:
        return $this->hmac($sign);
    }

    private function hmac($string)
    {
        $hmac = hash_hmac('sha1', utf8_encode($string), $this->secretKey);
        return $this->encodeBase64($hmac);
    }

    private function encodeBase64( $string )
    {
        $encode = '';

        for ($i=0; $i < strlen($string); $i+=2)
        {
            $encode .= chr(hexdec(substr($string, $i, 2)));
        }

        return base64_encode($encode);
    }

    private function assignTimestamp()
    {
        return gmdate('D, d M Y H:i:s T');
    }

    private function assignNonce()
    {
        return md5(microtime() . mt_rand());
    }
}
