<?php

namespace App\Services\Vrcx;

/** A VRCX call failed. The message is safe to show the owner: it never contains the API key. */
class VrcxException extends \RuntimeException {}
