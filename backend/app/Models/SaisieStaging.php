<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaisieStaging extends Model
{
    use HasFactory;

    protected $table = 'saisies_staging';

    protected $fillable = [
        'reference',
        'cip',
        'telephone',
        'email',
        'cip_document_path',
        'cip_document_data',
        'cip_document_mime',
        'cip_document_name',
        'datesais',
        'nomop',
        'user_id',
        'statut',
        'admin_id',
        'validated_at',
        'motif_rejet',
    ];

    protected $casts = [
        'datesais'     => 'date',
        'validated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function police()
    {
        return $this->belongsTo(Police::class, 'reference', 'reference');
    }
}
