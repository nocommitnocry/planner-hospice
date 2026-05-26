<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Sollevata da PianoPdfService quando un piano non può essere stampato:
 * non esiste, oppure non è in stato `pubblicato` (la stampa è ammessa solo
 * per i piani pubblicati — vedi spec-pdf-piano-turni §2).
 *
 * Il controller fa la sua guardia e redirige con flash prima di arrivare qui;
 * questa eccezione è il backstop difensivo del service.
 */
final class PianoNonStampabileException extends RuntimeException
{
}
