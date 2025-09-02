<?php

namespace Arafa\Payments\Support;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class BasePaymentService
{
    protected string $base_url;
    protected array $header;

    /**
     * Main method to send request
     */
    protected function buildRequest(string $method, string $url, array|null $data = null, string $type = 'json'): \Illuminate\Http\JsonResponse
    {
        try {
            $response = $this->sendHttpRequest($method, $url, $data, $type);
            return $this->formatResponse($response);
        } catch (Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Send the HTTP request
     */
    private function sendHttpRequest(string $method, string $url, array|null $data, string $type): Response
    {
        return Http::withHeaders($this->header)->send($method, $this->base_url . $url, [
            $type => $data
        ]);
    }

    /**
     * Format the response to a consistent JSON structure
     */
    private function formatResponse(Response $response): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => $response->successful(),
            'status' => $response->status(),
            'data' => $response->json(),
        ], $response->status());
    }

    /**
     * Handle exceptions in a unified way
     */
    private function handleException(Exception $e): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'status' => 500,
            'message' => $e->getMessage(),
        ], 500);
    }
}
