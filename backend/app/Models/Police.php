<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Police extends Model
{
    use HasFactory;

    protected $table = 'polices';

    protected $fillable = [
        'reference',
        'ref_prec',
        'departement',
        'agence',
        'centre',
        'police',
        'etat_abo',
        'etat_comp',
        'nom_abonne',
        'ville',
        'cip',
        'telephone',
        'email',
        'cip_document_path',
        'cip_document_data',
        'cip_document_mime',
        'cip_document_name',
        'datesais',
        'nomop',
    ];

    protected $casts = [
        'departement' => 'integer',
        'agence'      => 'integer',
        'centre'      => 'integer',
        'datesais'    => 'date',
    ];

    /**
     * Saisies en staging associées à cette référence
     */
    public function saisiesStaging()
    {
        return $this->hasMany(SaisieStaging::class, 'reference', 'reference');
    }
}
