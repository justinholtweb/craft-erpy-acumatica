<?php

namespace justinholtweb\erpyacumatica\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\SessionAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Acumatica Cloud ERP, through the contract-based REST API.
 *
 * Two things shape this connector. Acumatica authenticates with a *session*, not a token, and it
 * counts concurrent sessions against the licence — so the cookie is established once, cached for
 * its life and shared across a whole run, and there is an explicit logout rather than leaking a
 * session per request.
 *
 * And every field in a contract-based payload is an object with a `value` inside it. Reading
 * `$row['InventoryID']` gives you an array, not a string, which is the first thing that catches
 * everybody writing against this API.
 *
 * Inventory is the one pull without a delta. The inventory summary inquiry it reads has no
 * modified date to filter on, and `StockItem.LastModifiedDateTime` does not move when stock does
 * — a shipment or a receipt changes the warehouse quantity, not the item, so filtering on it would
 * silently miss stock movements. Acumatica's `InventoryQuantityAvailable` inquiry takes a
 * `LastModifiedDateTime` parameter, but it is a per-item PUT inquiry documented on later Default
 * endpoints than the 20.200.001 this connector defaults to. So every inventory run reads the whole
 * warehouse, and Erpy's content hash makes each unchanged row a comparison, not a save.
 */
class AcumaticaConnector extends Connector
{
    public static function handle(): string
    {
        return 'acumatica';
    }

    public static function displayName(): string
    {
        return 'Acumatica';
    }

    public static function vendor(): string
    {
        return 'Acumatica';
    }

    public static function description(): string
    {
        return 'Acumatica Cloud ERP through the contract-based REST endpoint, with a shared session so the licence is not spent on logins.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://help.acumatica.com/Help?ScreenId=ShowWiki&pageid=e0f4b45a-5fca-4147-a109-7b0b5e0f4c9c';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 200)
            // No modified date on the inventory summary inquiry — see the class docblock.
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: 500)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: 200)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 200)
            ->withMultiCompany()
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::url('instanceUrl', Craft::t('erpy', 'Instance URL'), [
                'required' => true,
                'placeholder' => 'https://example.acumatica.com',
                'instructions' => Craft::t('erpy', 'No trailing path — Erpy adds the endpoint itself.'),
            ]),
            Field::text('endpointVersion', Craft::t('erpy', 'Endpoint version'), [
                'required' => true,
                'default' => '20.200.001',
                'instructions' => Craft::t('erpy', 'From System → Integration → Web Service Endpoints. Use the Default endpoint’s version.'),
            ]),

            Field::heading(
                Craft::t('erpy', 'Sign in'),
                Craft::t('erpy', 'Use a dedicated integration user. Acumatica counts concurrent sessions against your licence, and sharing a person’s login means their password change breaks the storefront.'),
            ),
            Field::text('username', Craft::t('erpy', 'Username'), ['required' => true]),
            Field::secret('password', Craft::t('erpy', 'Password'), ['required' => true]),
            Field::text('company', Craft::t('erpy', 'Company / tenant'), [
                'instructions' => Craft::t('erpy', 'Leave blank on a single-tenant instance.'),
            ]),
            Field::text('branch', Craft::t('erpy', 'Branch'), [
                'instructions' => Craft::t('erpy', 'Leave blank to use the user’s default branch.'),
            ]),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('warehouse', Craft::t('erpy', 'Warehouse'), [
                'instructions' => Craft::t('erpy', 'The warehouse stock is read from and orders are raised against.'),
            ]),
            Field::text('orderType', Craft::t('erpy', 'Sales order type'), [
                'default' => 'SO',
                'instructions' => Craft::t('erpy', 'The order type new orders are created as.'),
            ]),
            Field::boolean('holdNewOrders', Craft::t('erpy', 'Create orders on hold'), [
                'default' => true,
                'instructions' => Craft::t('erpy', 'On is the safe default: an order on hold can be reviewed before it is committed to a warehouse.'),
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new SessionAuth(
            login: function($connection, Transport $http): ?array {
                $response = $http->request('POST', $this->instanceUrl() . '/entity/auth/login', [
                    'json' => array_filter([
                        'name' => $connection->getSetting('username'),
                        'password' => $connection->getSetting('password'),
                        'company' => $connection->getSetting('company') ?: null,
                        'branch' => $connection->getSetting('branch') ?: null,
                    ], static fn($value) => $value !== null && $value !== ''),
                    'headers' => ['Content-Type' => 'application/json'],
                ]);

                if (!$response->ok()) {
                    return ['error' => $response->errorMessage()];
                }

                $cookie = $this->cookieFrom($response->headers);

                return $cookie !== null
                    ? ['cookie' => $cookie, 'ttl' => 1500]
                    : ['error' => 'Acumatica accepted the login but set no session cookie.'];
            },
            logout: function($connection, Transport $http, string $cookie): void {
                // Worth doing rather than letting it lapse: an abandoned session holds a licence
                // seat until Acumatica times it out.
                $http->request('POST', $this->instanceUrl() . '/entity/auth/logout', [
                    'headers' => ['Cookie' => $cookie],
                ]);
            },
            requiredFields: ['instanceUrl', 'username', 'password'],
        );
    }

    protected function buildTransport(): Transport
    {
        return (new Transport())
            ->setBaseUri(sprintf(
                '%s/entity/Default/%s',
                $this->instanceUrl(),
                (string)$this->setting('endpointVersion', '20.200.001'),
            ))
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->setRateLimit(4)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('StockItem', ['$top' => 1, '$select' => 'InventoryID']);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match (true) {
                $response->status === 401 => [Craft::t('erpy', 'Check the username, password and tenant. If the user is locked out, Acumatica answers 401 exactly as it does for a wrong password.')],
                $response->status === 403 => [Craft::t('erpy', 'The user signed in but the endpoint is not exposed to their role, or the Default endpoint version is wrong.')],
                $response->status === 404 => [Craft::t('erpy', 'Check the endpoint version under System → Integration → Web Service Endpoints — it is part of the URL.')],
                default => [],
            });
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to Acumatica.'), [
            Craft::t('erpy', 'Endpoint') => (string)$this->setting('endpointVersion'),
            Craft::t('erpy', 'Tenant') => (string)($this->setting('company') ?: Craft::t('erpy', 'default')),
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        return $this->page('StockItem', $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)$this->v($row, 'InventoryID'),
                'name' => (string)$this->v($row, 'Description'),
                'enabled' => in_array((string)$this->v($row, 'ItemStatus'), ['Active', 'No Purchases'], true),
                // Acumatica has four flavours of "do not sell this": Inactive, No Sales,
                // No Request and Marked for Deletion. Only Active and No Purchases are sellable.
                'blocked' => !in_array((string)$this->v($row, 'ItemStatus'), ['Active', 'No Purchases'], true),
                'category' => $this->v($row, 'ItemClass'),
                'unitOfMeasure' => $this->v($row, 'BaseUOM'),
                'price' => $this->float($this->v($row, 'DefaultPrice')),
                'cost' => $this->float($this->v($row, 'LastCost')),
                'weight' => $this->float($this->v($row, 'DimensionWeight')),
                'taxCategory' => $this->v($row, 'TaxCategory'),
                'tracksInventory' => (string)$this->v($row, 'ItemType') !== 'Non-Stock Item',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)$this->v($row, 'InventoryID'),
                'modifiedAt' => $this->date($this->v($row, 'LastModifiedDateTime')),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $warehouse = (string)$this->setting('warehouse', '');
        $filters = $warehouse !== '' ? ["WarehouseID eq '" . $this->escape($warehouse) . "'"] : [];

        // The inventory summary inquiry is the endpoint that answers "how many can I sell?" —
        // reading StockItem instead gives a company-wide figure that ignores allocations.
        return $this->page('InventorySummaryInquiry', $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)$this->v($row, 'InventoryID'),
                'warehouse' => $this->v($row, 'WarehouseID'),
                'onHand' => (float)$this->float($this->v($row, 'QtyOnHand')),
                'available' => $this->float($this->v($row, 'QtyAvailable')),
                'allocated' => $this->float($this->v($row, 'QtyAllocated')),
                'incoming' => $this->float($this->v($row, 'QtyPOOrders')),
                'remoteId' => (string)($row['id'] ?? ''),
                'raw' => $row,
            ]);
        }, extraFilters: $filters, deltaField: null);
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        return $this->page('Customer', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            $main = $row['MainContact'] ?? [];
            $address = $main['Address'] ?? [];

            return new ErpCustomer([
                'code' => (string)$this->v($row, 'CustomerID'),
                'name' => (string)$this->v($row, 'CustomerName'),
                'email' => $this->v($main, 'Email'),
                'phone' => $this->v($main, 'Phone1'),
                'enabled' => (string)$this->v($row, 'Status') === 'Active',
                'onHold' => in_array((string)$this->v($row, 'Status'), ['Hold', 'Credit Hold', 'One-Time'], true),
                'currency' => $this->v($row, 'CurrencyID'),
                'priceListCode' => $this->v($row, 'PriceClassID'),
                'customerGroupCode' => $this->v($row, 'CustomerClass'),
                'paymentTermsCode' => $this->v($row, 'Terms'),
                'taxId' => $this->v($row, 'TaxRegistrationID'),
                'creditLimit' => $this->float($this->v($row, 'CreditLimit')),
                'addresses' => $address ? [new ErpAddress([
                    'type' => ErpAddress::TYPE_BILLING,
                    'fullName' => (string)$this->v($row, 'CustomerName'),
                    'addressLine1' => $this->v($address, 'AddressLine1'),
                    'addressLine2' => $this->v($address, 'AddressLine2'),
                    'locality' => $this->v($address, 'City'),
                    'administrativeArea' => $this->v($address, 'State'),
                    'postalCode' => $this->v($address, 'PostalCode'),
                    'countryCode' => $this->v($address, 'Country'),
                    'isDefault' => true,
                ])] : [],
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)$this->v($row, 'CustomerID'),
                'modifiedAt' => $this->date($this->v($row, 'LastModifiedDateTime')),
                'raw' => $row,
            ]);
        }, expand: 'MainContact,MainContact/Address');
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        return $this->page('Customer', $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)$this->v($row, 'CustomerID'),
                'currency' => (string)($this->v($row, 'CurrencyID') ?: 'USD'),
                'creditLimit' => $this->float($this->v($row, 'CreditLimit')),
                'balance' => (float)($this->float($this->v($row, 'Balance')) ?? 0),
                'onHold' => in_array((string)$this->v($row, 'Status'), ['Hold', 'Credit Hold'], true),
                'paymentTermsCode' => $this->v($row, 'Terms'),
                'raw' => $row,
            ]);
        }, select: 'CustomerID,CurrencyID,CreditLimit,Balance,Status,Terms', deltaField: null);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page('SalesOrder', $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)$this->v($row, 'Status');

            return new ErpOrderStatus([
                'orderNumber' => (string)$this->v($row, 'CustomerOrder'),
                'status' => $status,
                'statusCode' => $status,
                'isOnHold' => in_array($status, ['On Hold', 'Credit Hold', 'Pending Approval'], true),
                'isCancelled' => $status === 'Cancelled',
                'isPicking' => $status === 'Open',
                'isShipped' => in_array($status, ['Shipping', 'Completed'], true),
                'isPartiallyShipped' => $status === 'Back Order',
                'isInvoiced' => $status === 'Completed',
                'isClosed' => $status === 'Completed',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)$this->v($row, 'OrderNbr'),
                'modifiedAt' => $this->date($this->v($row, 'LastModifiedDateTime')),
                'raw' => $row,
            ]);
        }, select: 'OrderNbr,OrderType,CustomerOrder,Status,LastModifiedDateTime');
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        return $this->page('Shipment', $criteria, Entity::SHIPMENT, function(array $row): ErpShipment {
            $packages = $row['Packages'] ?? [];
            $first = is_array($packages) ? ($packages[0] ?? []) : [];
            $orders = $row['Orders'] ?? [];
            $order = is_array($orders) ? ($orders[0] ?? []) : [];

            $lines = [];

            foreach ((array)($row['Details'] ?? []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }

                $lines[] = [
                    'sku' => (string)$this->v($detail, 'InventoryID'),
                    'quantity' => (float)($this->float($this->v($detail, 'ShippedQty')) ?? 0),
                ];
            }

            return new ErpShipment([
                'orderNumber' => (string)$this->v($order, 'CustomerOrderNbr'),
                'shipmentNumber' => (string)$this->v($row, 'ShipmentNbr'),
                'trackingNumber' => $this->v($first, 'TrackingNbr'),
                'carrier' => $this->v($row, 'ShipVia'),
                'shippedAt' => $this->date($this->v($row, 'ShipmentDate')),
                'warehouse' => $this->v($row, 'WarehouseID'),
                'lines' => $lines,
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($this->v($row, 'LastModifiedDateTime')),
                'raw' => $row,
            ]);
        }, expand: 'Details,Packages,Orders');
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        return $this->page('Invoice', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($this->float($this->v($row, 'Amount')) ?? 0);
            $balance = (float)($this->float($this->v($row, 'Balance')) ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)$this->v($row, 'ReferenceNbr'),
                'customerCode' => (string)$this->v($row, 'Customer'),
                'issuedAt' => $this->date($this->v($row, 'Date')),
                'dueAt' => $this->date($this->v($row, 'DueDate')),
                'currency' => (string)($this->v($row, 'CurrencyID') ?: 'USD'),
                'total' => $total,
                'balance' => $balance,
                'amountPaid' => $total - $balance,
                'isPaid' => abs($balance) < 0.005,
                'isCreditNote' => (string)$this->v($row, 'Type') === 'Credit Memo',
                'status' => (string)$this->v($row, 'Status'),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($this->v($row, 'LastModifiedDateTime')),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'Acumatica needs a customer id. Set a guest customer code on the order mapping, or link this customer to an Acumatica account.'));
        }

        // Acumatica's PUT on a top-level entity is an upsert keyed on the entity's natural key.
        // `CustomerOrder` holds the Commerce order number, so asking first is how a retry avoids
        // becoming a second sales order.
        $existing = $this->findOrder($document->orderNumber);

        if ($existing !== null && $remoteId === null) {
            return PushResult::alreadyExists(
                (string)($existing['id'] ?? ''),
                (string)$this->v($existing, 'OrderNbr'),
            );
        }

        $details = [];

        foreach ($document->lines as $line) {
            $details[] = array_filter([
                'InventoryID' => $this->wrap($line->sku),
                'OrderQty' => $this->wrap($line->quantity),
                'UnitPrice' => $this->wrap($line->unitPrice),
                'ManualPrice' => $this->wrap(true),
                'LineDescription' => $line->description ? $this->wrap($line->description) : null,
                'WarehouseID' => $line->warehouse ? $this->wrap($line->warehouse) : null,
            ], static fn($value) => $value !== null);
        }

        $payload = array_filter([
            'OrderType' => $this->wrap((string)$this->setting('orderType', 'SO')),
            'CustomerID' => $this->wrap($document->customerCode),
            'CustomerOrder' => $this->wrap(mb_substr($document->orderNumber, 0, 50)),
            'Date' => $this->wrap(($document->orderedAt ?? new DateTime())->format('Y-m-d')),
            'Description' => $document->customerNote ? $this->wrap(mb_substr($document->customerNote, 0, 250)) : null,
            'CurrencyID' => $document->currency ? $this->wrap($document->currency) : null,
            'Hold' => $this->wrap($this->boolSetting('holdNewOrders', true)),
            'Details' => $details,
        ], static fn($value) => $value !== null);

        if ($document->shippingAddress && !$document->shippingAddress->isEmpty()) {
            $payload['ShipToAddress'] = $this->addressPayload($document->shippingAddress);
            $payload['ShipToAddressOverride'] = $this->wrap(true);

            if ($document->shippingAddress->fullName) {
                $payload['ShipToContact'] = [
                    'OverrideContact' => $this->wrap(true),
                    'DisplayName' => $this->wrap($document->shippingAddress->fullName),
                    'Email' => $document->email ? $this->wrap($document->email) : null,
                ];
            }
        }

        foreach ($document->customFields as $field => $value) {
            $payload[$field] = $this->wrap($value);
        }

        $response = $this->transport()->put('SalesOrder', $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        $body = $response->json_();

        return PushResult::ok(
            (string)($body['id'] ?? $document->orderNumber),
            (string)$this->v($body, 'OrderNbr'),
            $body,
        );
    }

    private function addressPayload($address): array
    {
        return array_filter([
            'OverrideAddress' => $this->wrap(true),
            'AddressLine1' => $address->addressLine1 ? $this->wrap($address->addressLine1) : null,
            'AddressLine2' => $address->addressLine2 ? $this->wrap($address->addressLine2) : null,
            'City' => $address->locality ? $this->wrap($address->locality) : null,
            'State' => $address->administrativeArea ? $this->wrap($address->administrativeArea) : null,
            'PostalCode' => $address->postalCode ? $this->wrap($address->postalCode) : null,
            'Country' => $address->countryCode ? $this->wrap($address->countryCode) : null,
        ], static fn($value) => $value !== null);
    }

    private function findOrder(string $orderNumber): ?array
    {
        if ($orderNumber === '') {
            return null;
        }

        $response = $this->transport()->get('SalesOrder', [
            '$filter' => "CustomerOrder eq '" . $this->escape($orderNumber) . "'",
            '$select' => 'OrderNbr,OrderType,CustomerOrder',
            '$top' => 1,
        ]);

        if (!$response->ok()) {
            return null;
        }

        $rows = $response->json_();

        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(
        string $entityName,
        FetchCriteria $criteria,
        string $entity,
        callable $make,
        ?string $deltaField = 'LastModifiedDateTime',
        ?string $select = null,
        ?string $expand = null,
        array $extraFilters = [],
    ): Page {
        $limit = $this->pageSize($entity, $criteria);
        $skip = (int)($criteria->cursor ?? 0);

        $query = ['$top' => $limit, '$skip' => $skip];

        if ($select !== null) {
            $query['$select'] = $select;
        }

        if ($expand !== null) {
            $query['$expand'] = $expand;
        }

        $filters = $extraFilters;

        if ($deltaField !== null && $criteria->since instanceof DateTimeInterface) {
            // Acumatica wants an unquoted ISO 8601 literal here; quoting it produces a 500 with
            // a stack trace rather than a message.
            $filters[] = sprintf("%s gt datetimeoffset'%s'", $deltaField, $criteria->since->format('Y-m-d\TH:i:s\Z'));
        }

        foreach ($criteria->filters as $field => $value) {
            $filters[] = sprintf("%s eq '%s'", $field, $this->escape((string)$value));
        }

        if ($filters !== []) {
            $query['$filter'] = implode(' and ', $filters);
        }

        $response = $this->transport()->get($entityName, $query);

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'Acumatica refused to read %s: %s',
                $entityName,
                $response->errorMessage(),
            ));
        }

        $rows = $response->json_();
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        // Acumatica sends no paging metadata at all: a short page is the only signal that the
        // end has been reached.
        return new Page($items, count($rows) >= $limit ? (string)($skip + $limit) : null);
    }

    /**
     * Unwrap a contract-based field. Every value in an Acumatica payload arrives as
     * `{"value": …}`, and reading the field directly gives you an array.
     */
    private function v(array $row, string $field): mixed
    {
        $value = $row[$field] ?? null;

        if (is_array($value)) {
            return $value['value'] ?? null;
        }

        return $value;
    }

    private function wrap(mixed $value): array
    {
        return ['value' => $value];
    }

    private function float(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }

    private function instanceUrl(): string
    {
        return rtrim((string)$this->setting('instanceUrl'), '/');
    }

    /**
     * Acumatica sets two cookies and needs both back; anything else and the next request is
     * answered as an anonymous one.
     */
    private function cookieFrom(array $headers): ?string
    {
        $cookies = [];

        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Set-Cookie') !== 0) {
                continue;
            }

            foreach ((array)$values as $value) {
                $pair = strtok((string)$value, ';');

                if ($pair) {
                    $cookies[] = $pair;
                }
            }
        }

        return $cookies !== [] ? implode('; ', $cookies) : null;
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
