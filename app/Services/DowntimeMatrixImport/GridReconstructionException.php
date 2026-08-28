<?php

namespace App\Services\DowntimeMatrixImport;

/**
 * Thrown when the PDF's text fragments can't be confidently reassembled
 * into the expected matrix grid structure - "best effort but never a
 * silent guess" applies to the grid itself, not just facility resolution.
 */
class GridReconstructionException extends \RuntimeException
{
}
