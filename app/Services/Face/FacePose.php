<?php

namespace App\Services\Face;

final class FacePose
{
    public const FRONT = 'FRONT';
    public const LEFT = 'LEFT';
    public const RIGHT = 'RIGHT';

    public const ALL = [self::FRONT, self::LEFT, self::RIGHT];
}
