<?php

namespace Arafa\Payments\Gateways;

use Exception;
use Illuminate\Support\Facades\Http;

class BasePaymentService
{
    protected $base_url;
    protected array $header;

    /**
     * Main method to send request
     */
    protected function buildRequest($method, $url, $data = null, $type = 'json')
    {
        try {
            $response = $this->send($method, $url, $data, $type);
            return $this->successResponse($response);
        } catch (Exception $e) {
            return $this->errorResponse($e);
        }
    }

    /**
     * Send HTTP request with headers
     */
    private function send($method, $url, $data, $type)
    {
        return Http::withHeaders($this->header)->send($method, $this->buildUrl($url), [
            $type => $data
        ]);
    }

    /**
     * Build full URL
     */
    private function buildUrl($url): string
    {
        return $this->base_url . $url;
    }

    /**
     * Format success response
     */
    private function successResponse($response): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => $response->successful(),
            'status'  => $response->status(),
            'data'    => $response->json(),
        ], $response->status());
    }

    /**
     * Format error response
     */
    private function errorResponse(Exception $e): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'status'  => 500,
            'message' => $e->getMessage(),
        ], 500);
    }
}
