<?php

declare(strict_types=1);

namespace Vendor\ContaoLivePreviewBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class ContaoLivePreviewBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
