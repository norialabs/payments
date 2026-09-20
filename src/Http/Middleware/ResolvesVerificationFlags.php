<?php

namespace NoriaLabs\Payments\Http\Middleware;

use NoriaLabs\Payments\Exceptions\ConfigurationException;

trait ResolvesVerificationFlags
{
    /**
     * @param  array<array-key, string>  $flags
     * @return array{0: bool|null, 1: bool|null} [$enforceIpWhitelist, $verifySignature]
     */
    protected function resolveVerificationFlags(array $flags): array
    {
        $enforceIpWhitelist = null;
        $verifySignature = null;

        foreach ($flags as $flag) {
            switch (strtolower(trim($flag))) {
                case '':
                    break;
                case 'signature':
                    $verifySignature = true;
                    break;
                case 'no-signature':
                case 'without-signature':
                    $verifySignature = false;
                    break;
                case 'ip':
                case 'ip-whitelist':
                    $enforceIpWhitelist = true;
                    break;
                case 'no-ip':
                case 'no-ip-whitelist':
                case 'without-ip':
                    $enforceIpWhitelist = false;
                    break;
                default:
                    throw new ConfigurationException(
                        "Unknown payment verification middleware option [{$flag}]. "
                        .'Supported options: signature, no-signature, ip, no-ip.'
                    );
            }
        }

        return [$enforceIpWhitelist, $verifySignature];
    }
}
