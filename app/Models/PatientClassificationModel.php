<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientClassificationModel extends Model
{
    protected $table = 'lib_hci_class';

    public $timestamps = false;
    public $incrementing = false;

    protected $primaryKey = 'CLASS_CODE';
    protected $keyType = 'string';

    protected $fillable = [
        'CLASS_CODE',
        'CLASS_DEF',
    ];

    public function patients()
    {
        return $this->hasMany(PatientMasterlist::class, 'classification', 'CLASS_DEF');
    }
}
