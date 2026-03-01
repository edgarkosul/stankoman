<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ShippingMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $statusValues = array_map(static fn (OrderStatus $case): string => $case->value, OrderStatus::cases());
            $paymentStatusValues = array_map(static fn (PaymentStatus $case): string => $case->value, PaymentStatus::cases());
            $shippingValues = array_map(static fn (ShippingMethod $case): string => $case->value, ShippingMethod::cases());

            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('order_date')->index();
            $table->unsignedSmallInteger('seq');
            $table->string('order_number', 32)->unique();
            $table->unique(['order_date', 'seq']);

            $table->enum('status', $statusValues)
                ->default(OrderStatus::Submitted->value)
                ->index();
            $table->enum('payment_status', $paymentStatusValues)
                ->default(PaymentStatus::Awaiting->value)
                ->index();

            $table->boolean('is_company')->default(false);
            $table->string('customer_name');
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 32);

            $table->string('company_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();

            $table->enum('shipping_method', $shippingValues)
                ->default(ShippingMethod::Delivery->value)
                ->index();
            $table->string('shipping_country', 64)->nullable();
            $table->string('shipping_region', 128)->nullable();
            $table->string('shipping_city', 128)->nullable();
            $table->string('shipping_street', 255)->nullable();
            $table->string('shipping_house', 32)->nullable();
            $table->string('shipping_postcode', 16)->nullable();
            $table->string('pickup_point_id', 64)->nullable();
            $table->text('shipping_comment')->nullable();

            $table->string('payment_method', 32)->nullable();

            $table->decimal('items_subtotal', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('shipping_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->char('currency', 3)->default('RUB');

            $table->string('public_hash', 64)->unique();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
