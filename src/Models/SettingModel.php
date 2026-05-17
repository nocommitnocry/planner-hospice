<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Setting operativi: hospice (reparto), ucp_dom (cure palliative domiciliari).
 *
 * I record sono "anagrafica di sistema": vengono seedati dalla migrazione,
 * non c'è una UI di gestione. Qui esponiamo solo le letture necessarie ai
 * Validator e alle viste.
 */
final class SettingModel extends BaseModel
{
    protected string $table = 'setting';

    protected array $fillable = [
        'codice',
        'nome',
        'descrizione',
        'attivo',
        'ordine_visualizzazione',
    ];

    /** @return list<array<string,mixed>> */
    public function listAttivi(): array
    {
        return $this->findBy(['attivo' => 1], 'ordine_visualizzazione', 'ASC');
    }

    public function findByCodice(string $codice): ?array
    {
        return $this->findOneBy(['codice' => $codice]);
    }
}
