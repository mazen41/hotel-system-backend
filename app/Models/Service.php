<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'guest_id',
        'reservation_id',
        'name',
        'description',
        'fees',
        'invoice_image',
        'created_by',
    ];

    protected $casts = [
        'fees' => 'decimal:2',
    ];

    protected $appends = [
        'invoice_image_url',
    ];

    public function getInvoiceImageUrlAttribute()
    {
        if ($this->invoice_image) {
            return Storage::url($this->invoice_image);
        }
        return null;
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
