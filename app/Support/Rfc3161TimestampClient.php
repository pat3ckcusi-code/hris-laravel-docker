<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Minimal RFC 3161 (Time-Stamp Protocol) client: builds a SHA-256
 * TimeStampReq, POSTs it to a TSA, and parses the TimeStampResp far enough
 * to report PKIStatus. Hand-rolled DER encode/decode rather than shelling
 * out to `openssl ts`, since only a handful of fixed-shape ASN.1 structures
 * are involved (SEQUENCE, INTEGER, OCTET STRING, BOOLEAN, one fixed OID).
 */
class Rfc3161TimestampClient
{
    // AlgorithmIdentifier ::= SEQUENCE { OID 2.16.840.1.101.3.4.2.1 (sha256), NULL }
    private const SHA256_ALGORITHM_IDENTIFIER = "\x30\x0D\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00";

    /**
     * Build a minimal RFC 3161 TimeStampReq for a SHA-256 digest.
     *
     * TimeStampReq ::= SEQUENCE {
     *     version         INTEGER { v1(1) },
     *     messageImprint  MessageImprint,
     *     certReq         BOOLEAN DEFAULT FALSE
     * }
     * MessageImprint ::= SEQUENCE { hashAlgorithm AlgorithmIdentifier, hashedMessage OCTET STRING }
     */
    public function buildRequest(string $sha256Digest, bool $certReq = true): string
    {
        if (strlen($sha256Digest) !== 32) {
            throw new InvalidArgumentException('SHA-256 digest must be exactly 32 bytes.');
        }

        $hashedMessage = "\x04\x20".$sha256Digest;
        $messageImprintContent = self::SHA256_ALGORITHM_IDENTIFIER.$hashedMessage;
        $messageImprint = $this->wrapSequence($messageImprintContent);

        $content = "\x02\x01\x01".$messageImprint; // version INTEGER 1

        if ($certReq) {
            $content .= "\x01\x01\xFF"; // BOOLEAN TRUE
        }

        return $this->wrapSequence($content);
    }

    /**
     * POST a TimeStampReq to the TSA and report the outcome.
     *
     * @return array{granted: bool, unreachable: bool, status: int, statusText: string, raw: string}
     */
    public function query(string $tsaUrl, string $requestDer, int $timeoutSeconds = 15): array
    {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->withBody($requestDer, 'application/timestamp-query')
                ->post($tsaUrl);
        } catch (Throwable $e) {
            return $this->unreachableResult('TSA unreachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $this->unreachableResult(
                "TSA unreachable: HTTP {$response->status()}",
                (string) $response->body()
            );
        }

        return $this->parseResponse($response->body());
    }

    /**
     * Parse a TimeStampResp far enough to extract PKIStatus.
     *
     * TimeStampResp ::= SEQUENCE { status PKIStatusInfo, timeStampToken TimeStampToken OPTIONAL }
     * PKIStatusInfo ::= SEQUENCE { status PKIStatus, statusString PKIFreeText OPTIONAL, ... }
     * PKIStatus ::= INTEGER (0=granted, 1=grantedWithMods, 2=rejection, 3=waiting, ...)
     *
     * @return array{granted: bool, unreachable: bool, status: int, statusText: string, raw: string}
     */
    public function parseResponse(string $der): array
    {
        try {
            [$respContent] = $this->readTlv($der, 0);
            [$statusInfoContent] = $this->readTlv($respContent, 0);
            [$statusValue] = $this->readTlv($statusInfoContent, 0);

            $status = $this->decodeInteger($statusValue);

            return [
                'granted' => in_array($status, [0, 1], true),
                'unreachable' => false,
                'status' => $status,
                'statusText' => $this->statusLabel($status),
                'raw' => $der,
            ];
        } catch (Throwable $e) {
            return [
                'granted' => false,
                'unreachable' => false,
                'status' => -2,
                'statusText' => 'TSA response could not be parsed: '.$e->getMessage(),
                'raw' => $der,
            ];
        }
    }

    /**
     * @return array{granted: bool, unreachable: bool, status: int, statusText: string, raw: string}
     */
    private function unreachableResult(string $message, string $raw = ''): array
    {
        return [
            'granted' => false,
            'unreachable' => true,
            'status' => -1,
            'statusText' => $message,
            'raw' => $raw,
        ];
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            0 => 'granted',
            1 => 'grantedWithMods',
            2 => 'rejection',
            3 => 'waiting',
            4 => 'revocationWarning',
            5 => 'revocationNotification',
            default => "unknown ({$status})",
        };
    }

    private function wrapSequence(string $content): string
    {
        return "\x30".$this->encodeLength(strlen($content)).$content;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * Read one TLV element starting at $offset in $der.
     *
     * @return array{0: string, 1: int} [content bytes, offset after this element]
     */
    private function readTlv(string $der, int $offset): array
    {
        if (! isset($der[$offset + 1])) {
            throw new RuntimeException('Truncated DER data.');
        }

        $lengthByte = ord($der[$offset + 1]);
        $contentStart = $offset + 2;

        if ($lengthByte < 128) {
            $length = $lengthByte;
        } else {
            $numLengthBytes = $lengthByte & 0x7F;
            $length = 0;
            for ($i = 0; $i < $numLengthBytes; $i++) {
                if (! isset($der[$contentStart + $i])) {
                    throw new RuntimeException('Truncated DER length.');
                }
                $length = ($length << 8) | ord($der[$contentStart + $i]);
            }
            $contentStart += $numLengthBytes;
        }

        if (strlen($der) < $contentStart + $length) {
            throw new RuntimeException('Truncated DER content.');
        }

        return [substr($der, $contentStart, $length), $contentStart + $length];
    }

    private function decodeInteger(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        return $value;
    }
}
