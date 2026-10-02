<?php

namespace App\Services;

class IpNetworkMatcher
{
    public function isValid(string $network): bool
    {
        return $this->parse($network) !== null;
    }

    /** @param array<int, string> $networks */
    public function matchesAny(string $ipAddress, array $networks): bool
    {
        foreach ($networks as $network) {
            if ($this->matches($ipAddress, $network)) {
                return true;
            }
        }

        return false;
    }

    public function matches(string $ipAddress, string $network): bool
    {
        $parsedNetwork = $this->parse($network);
        $packedAddress = @inet_pton($ipAddress);
        if ($parsedNetwork === null || $packedAddress === false) {
            return false;
        }

        [$packedNetwork, $prefixLength] = $parsedNetwork;
        if (strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        if ($wholeBytes > 0 && substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    /** @return array{string, int}|null */
    private function parse(string $network): ?array
    {
        $parts = explode('/', $network);
        if (count($parts) > 2) {
            return null;
        }

        $packedNetwork = @inet_pton($parts[0]);
        if ($packedNetwork === false) {
            return null;
        }

        $maximumPrefix = strlen($packedNetwork) * 8;
        if (count($parts) === 1) {
            return [$packedNetwork, $maximumPrefix];
        }

        if (! preg_match('/^\d+$/', $parts[1])) {
            return null;
        }

        $prefixLength = (int) $parts[1];
        if ($prefixLength > $maximumPrefix) {
            return null;
        }

        return [$packedNetwork, $prefixLength];
    }
}
