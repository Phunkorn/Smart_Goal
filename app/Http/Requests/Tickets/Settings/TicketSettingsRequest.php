<?php

namespace App\Http\Requests\Tickets\Settings;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ฐานของคำขอในหน้าตั้งค่า Smart Ticket — ทุก action ต้องผ่าน TicketPolicy::manageSettings
 * (route ยังมี middleware admin เป็นชั้นแรก)
 */
abstract class TicketSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageSettings', Ticket::class);
    }
}
