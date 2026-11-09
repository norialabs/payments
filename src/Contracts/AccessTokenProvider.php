<?php

namespace Noria\Payments\Contracts;

interface AccessTokenProvider
{
    public function getAccessToken(bool $forceRefresh = false): string;
}
