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

        if (in_array($response->status(), [401, 403], true)) {
            throw new SGroupSessionExpiredException(
                "S-Etukortti refused the session (HTTP {$response->status()})."
            );
        }

        $json = $response->throw()->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'OK' || ! empty($json['errors'])) {
            throw new RuntimeException('S-Etukortti answered with an error: '.json_encode($json['errors'] ?? $json));
        }

        $this->session->forceFill(['last_ok_at' => now()])->save();

        return is_array($json['data'] ?? null) ? $json['data'] : [];
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
