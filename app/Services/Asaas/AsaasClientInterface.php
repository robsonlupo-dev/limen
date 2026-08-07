<?php

namespace App\Services\Asaas;

interface AsaasClientInterface
{
    public function createCustomer(array $data): array;

    public function createPixCharge(array $data): array;

    public function getPixQrCode(string $chargeId): array;

    public function getPayment(string $chargeId): array;

    public function createTransfer(array $data): array;

    public function getTransfer(string $transferId): array;

    public function findTransfersByExternalReference(string $externalReference): array;

    /**
     * Create a recurring credit-card subscription. The first request carries the
     * raw card (creditCard + creditCardHolderInfo); Asaas tokenizes it and the
     * response returns a reusable creditCardToken plus last4/brand. The PAN is
     * never persisted by us.
     */
    public function createSubscription(array $data): array;

    public function getSubscription(string $subscriptionId): array;

    /** The charges (payments) Asaas generated for a subscription. */
    public function getSubscriptionPayments(string $subscriptionId): array;

    public function cancelSubscription(string $subscriptionId): array;

    /**
     * Is this the fake/simulated gateway (dev, staging, tests)?
     *
     * O reconcile usa isto para decidir se pula ids sintéticos: o Fake gera
     * `pay_fake_...` e os resolve normalmente; só o gateway REAL devolve 404 neles.
     */
    public function isFake(): bool;
}
