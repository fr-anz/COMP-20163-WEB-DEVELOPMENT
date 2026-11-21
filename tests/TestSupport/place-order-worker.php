<?php

declare(strict_types=1);

// Child process for the no-oversell test: places one order for one unit of
// a product from its own database connection, after a shared start time,
// and prints ok/stock/<class> on stdout.

use Habi\Services\CheckoutException;
use Habi\Services\OrderService;
use Habi\Services\PaymentProofService;
use Habi\Support\Database;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dsn, $proofDirectory, $productId, $price, $method, $key, $proof, $startAt] = $argv;

$payment = match ($method) {
    'cod' => ['method' => 'cod'],
    'transfer' => ['method' => 'transfer', 'proof' => $proof, 'sender_reference' => 'REF-' . $key],
    default => ['method' => 'card', 'result' => 'approved', 'last4' => '4242', 'brand' => 'Visa'],
};
$shipping = [
    'name' => 'Race Buyer', 'email' => 'race@example.com', 'phone' => '+63 917 123 4567',
    'address1' => '1 Race St', 'address2' => '', 'city' => 'Quezon City', 'postal_code' => '1108',
    'region' => 'metro_manila',
];

$orders = new OrderService(Database::connection($dsn), new PaymentProofService($proofDirectory));
while (microtime(true) < (float) $startAt) {
    usleep(1000);
}
try {
    $orders->placeOrder(
        [['product_id' => (int) $productId, 'quantity' => 1, 'price_centavos' => (int) $price]],
        $shipping,
        $payment,
        $key
    );
    echo 'ok';
} catch (CheckoutException $failure) {
    echo $failure->reason;
} catch (Throwable $failure) {
    echo get_class($failure) . ': ' . $failure->getMessage();
}
