<?php

declare(strict_types=1);

namespace App\Services\SGroup;

use App\Exceptions\SGroupSessionExpiredException;
use App\Models\SGroupSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The receipt API behind www.s-etukortti.fi.
 *
 * Undocumented: these are the calls the site's own front end makes. A request
 * is authorised by two cookies — `sessionId` (the login) and `xsrf-token` —
 * plus the xsrf value repeated in an `x-csrf-token` header (double-submit CSRF).
 *
 * Every answer is wrapped as {data, status, errors, correlationId}; a 200 with
 * status other than "OK" is still a failure.
 */
class SEtukorttiClient
{
    public function __construct(private readonly SGroupSession $session) {}

    /**
     * Receipt headers in a date range, both ends inclusive. No line items here.
     *
     * @return array<int, array{receiptId: string, placeOfBusinessCode: string, placeOfBusinessName: string, transactionStart: string, totalAmount: float|int}>
     */
    public function receipts(CarbonInterface $from, CarbonInterface $to): array
    {
        $data = $this->get('/receipts', [
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
        ]);

        return array_values(array_filter($data['receipts'] ?? [], 'is_array'));
    }

    /**
     * One receipt: the header fields plus `receiptData`, the receipt as printed.
     *
     * @return array<string, mixed>
     */
    public function receipt(string $receiptId): array
    {
        $receipt = $this->get('/receipts/'.rawurlencode($receiptId))['receipt'] ?? null;

        if (! is_array($receipt)) {
            throw new RuntimeException("S-Etukortti returned no receipt for {$receiptId}.");
        }

        return $receipt;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->http()->get(rtrim((string) config('sgroup.base_url'), '/').$path, $query);

        $json = $response->json();

        // A dead session is not a 401: the API answers BAD_REQUEST with
        // "Failed to get session by id" in the error envelope. Check the
        // envelope before throw(), or it surfaces as an ordinary failure and
        // the job retries a login that will never work again.
        if (in_array($response->status(), [401, 403], true) || $this->isSessionError($json)) {
            throw new SGroupSessionExpiredException(
                "S-Etukortti refused the session (HTTP {$response->status()})."
            );
        }

        $response->throw();

        if (! is_array($json) || ($json['status'] ?? null) !== 'OK' || ! empty($json['errors'])) {
            throw new RuntimeException('S-Etukortti answered with an error: '.json_encode($json['errors'] ?? $json));
        }

        $this->session->forceFill(['last_ok_at' => now()])->save();

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    /**
     * Two answers mean the stored cookies will never work:
     * - "Failed to get session by id" (BAD_REQUEST) — the session is gone;
     * - "Failed to decrypt the string" (INTERNAL_ERROR, HTTP 500) — the
     *   sessionId value itself is mangled, typically copied URL-decoded from
     *   DevTools. Retrying either one is pointless; a new login is needed.
     */
    private function isSessionError(mixed $json): bool
    {
        if (! is_array($json) || ! is_array($json['errors'] ?? null)) {
            return false;
        }

        foreach ($json['errors'] as $error) {
            if (is_array($error) && preg_match('/\bsession\b|\bdecrypt/i', (string) ($error['errorMessage'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function http(): PendingRequest
    {
        $sid = $this->session->session_id;
        $xsrf = $this->session->xsrf_token;

        return Http::withHeaders([
            'Cookie' => "sessionId={$sid}; xsrf-token={$xsrf}",
            'x-csrf-token' => $xsrf,
            'Accept' => 'application/json',
            'Origin' => 'https://www.s-etukortti.fi',
            'Referer' => 'https://www.s-etukortti.fi/',
        ])->timeout((int) config('sgroup.timeout'));
    }
}
