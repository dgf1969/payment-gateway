<?php

namespace Arafa\Payments\Gateways;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Support\AlRajhiEncryptionService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class AlRajhiBankService extends BasePaymentService implements PaymentGatewayInterface
{
    protected string $id;
    protected string $name;
    protected string $password;
    protected string $mode;
    protected AlRajhiEncryptionService $encryptionService;

    public function __construct()
    {
        $this->name              = 'alrajhibank';
        $this->mode              = config("payments.{$this->name}.mode");
        $this->encryptionService = new AlRajhiEncryptionService($this->name, $this->mode);
        $this->base_url          = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->id                = config("payments.{$this->name}.{$this->mode}_transportal_id");
        $this->password          = config("payments.{$this->name}.{$this->mode}_password");
        $this->header            = $this->buildHeader();
    }

    public function buildHeader(): array
    {
        return ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    protected function buildPlainData(Request $request): array
    {
        return [[
            'id' => $this->id,
            'password' => $this->password,
            'action' => '1',
            'currencyCode' => '682',
            'errorURL' => config("payments.failed_url"),
            'responseURL' => config("payments.callback_url"),
            'trackId' => uniqid(),
            'amt' => $request->get('amount'),
        ]];
    }

    protected function buildEncryptedRequest(array $plainData): array
    {
        return [[
            'id' => $this->id,
            'trandata' => $this->encryptionService->encrypt(json_encode($plainData)),
            'errorURL' => config("payments.failed_url"),
            'responseURL' => config("payments.callback_url"),
        ]];
    }

    protected function processResponse(array $responseData): array
    {
        if (!($responseData['success'] ?? false) || !isset($responseData['data'][0]['result'])) {
            return ['success' => false, 'url' => config("payments.failed_url")];
        }

        [$paymentID, $url] = explode(':', $responseData['data'][0]['result'], 2);

        return ['success' => true, 'url' => "$url?PaymentID=$paymentID"];
    }

    public function sendPayment(Request $request): array
    {
        return $this->processResponse(
            $this->buildRequest(
                'POST',
                '/pg/payment/hosted.htm',
                $this->buildEncryptedRequest($this->buildPlainData($request))
            )->getData(true)
        );
    }

    public function callBack(Request $request): PaymentResponse
    {
        $data = json_decode(
            urldecode($this->encryptionService->decrypt($request->get('trandata'))),
            true
        );
        $raw = $data[0] ?? [];
        return new PaymentResponse(
            success: (($raw['result'] ?? '') === 'CAPTURED') && (($raw['authRespCode'] ?? '') === '00'),
            status: $raw['result'] ?? 'unknown',
            unique_id: $raw['transId'] ?? null,
            amount: isset($raw['amt']) ? (float)$raw['amt'] : null,
            currency: 'SAR',
            gateway_name: 'al_rajhi_bank',
            raw: $raw
        );
    }
}
