<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoodReceipt extends Model
{
    protected $connection = 'pgsql_second';
    protected $table = 'good_receipts';
    
    protected $fillable = [
        'dn_no',
        'date_gr',
        'po_no',
        'item_po',
        'qty',
        'plant',
        'sloc',
        'batch_no',
        'material_doc_no',
        'doc_year',
        'posting_date',
        'success',
        'error_message',
        'user_id',
        'users_table_id',
        'user_email',
        'department',
        'sap_request',
        'sap_response',
        'sap_endpoint',
        'response_time_ms',
        
        // ✅ ADD THESE TWO COLUMNS
        'logged_in_user_id',  // RFID of the logged-in account
        'rfid_user_id',       // RFID that was tapped for posting
    ];

    protected $casts = [
        'date_gr' => 'date',
        'posting_date' => 'date',
        'success' => 'boolean',
        'sap_request' => 'array',
        'sap_response' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relationship to User model
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'users_table_id', 'id');
    }

    /**
     * ✅ NEW: Relationship to logged-in user via RFID
     */
    public function loggedInUser()
    {
        return $this->belongsTo(\App\Models\UserSap::class, 'logged_in_user_id', 'id_card');
    }

    /**
     * ✅ NEW: Relationship to RFID user who posted
     */
    public function rfidUser()
    {
        return $this->belongsTo(\App\Models\UserSap::class, 'rfid_user_id', 'id_card');
    }

    /**
     * Scope for successful GRs
     */
    public function scopeSuccessful($query)
    {
        return $query->where('success', true);
    }

    /**
     * Scope for failed GRs
     */
    public function scopeFailed($query)
    {
        return $query->where('success', false);
    }

    /**
     * Scope for specific PO
     */
    public function scopeForPo($query, $poNo)
    {
        return $query->where('po_no', $poNo);
    }

    /**
     * Scope for specific PO item
     */
    public function scopeForPoItem($query, $poNo, $itemPo)
    {
        return $query->where('po_no', $poNo)
                    ->where('item_po', $itemPo);
    }

    /**
     * Scope for specific delivery note
     */
    public function scopeForDn($query, $dnNo)
    {
        return $query->where('dn_no', $dnNo);
    }

    /**
     * Scope for specific plant
     */
    public function scopeForPlant($query, $plant)
    {
        return $query->where('plant', $plant);
    }

    /**
     * Scope for specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * ✅ NEW: Scope for logged-in user RFID
     */
    public function scopeForLoggedInRfid($query, $rfid)
    {
        return $query->where('logged_in_user_id', $rfid);
    }

    /**
     * ✅ NEW: Scope for posting RFID
     */
    public function scopeForPostingRfid($query, $rfid)
    {
        return $query->where('rfid_user_id', $rfid);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_gr', [$startDate, $endDate]);
    }

    /**
     * Get total quantity received for a PO item
     */
    public static function getTotalReceivedQty($poNo, $itemPo)
    {
        return self::where('po_no', $poNo)
            ->where('item_po', $itemPo)
            ->where('success', true)
            ->sum('qty');
    }

    /**
     * Get GR history for a PO item
     */
    public static function getPoItemHistory($poNo, $itemPo)
    {
        return self::where('po_no', $poNo)
            ->where('item_po', $itemPo)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Check if GR was successful
     */
    public function isSuccessful()
    {
        return $this->success === true;
    }

    /**
     * Check if GR failed
     */
    public function isFailed()
    {
        return $this->success === false;
    }

    /**
     * Get formatted date for display
     */
    public function getFormattedDateAttribute()
    {
        return $this->date_gr ? $this->date_gr->format('d M Y') : null;
    }

    /**
     * Get SAP material document info
     */
    public function getSapDocumentInfo()
    {
        if (!$this->material_doc_no) {
            return null;
        }

        return [
            'material_doc_no' => $this->material_doc_no,
            'doc_year' => $this->doc_year,
            'posting_date' => $this->posting_date
        ];
    }

    /**
     * Get response time in seconds
     */
    public function getResponseTimeInSeconds()
    {
        return $this->response_time_ms ? round($this->response_time_ms / 1000, 2) : null;
    }

    /**
     * ✅ NEW: Check if posted by different person
     */
    public function isPostedByDifferentPerson()
    {
        return $this->logged_in_user_id !== $this->rfid_user_id;
    }

    /**
     * ✅ NEW: Get posting details
     */
    public function getPostingDetails()
    {
        $loggedInUser = $this->loggedInUser;
        $rfidUser = $this->rfidUser;

        return [
            'logged_in_account' => $loggedInUser ? $loggedInUser->user_id : 'Unknown',
            'logged_in_name' => $loggedInUser ? $loggedInUser->full_name : 'Unknown',
            'logged_in_rfid' => $this->logged_in_user_id,
            
            'posted_by_account' => $rfidUser ? $rfidUser->user_id : 'Unknown',
            'posted_by_name' => $rfidUser ? $rfidUser->full_name : 'Unknown',
            'posted_by_rfid' => $this->rfid_user_id,
            
            'is_different_person' => $this->isPostedByDifferentPerson()
        ];
    }

    /**
     * Get statistics for a specific PO
     */
    public static function getPoStatistics($poNo)
    {
        $grRecords = self::where('po_no', $poNo)->get();

        return [
            'total_grs' => $grRecords->count(),
            'successful_grs' => $grRecords->where('success', true)->count(),
            'failed_grs' => $grRecords->where('success', false)->count(),
            'total_qty_received' => $grRecords->where('success', true)->sum('qty'),
            'latest_gr' => $grRecords->sortByDesc('created_at')->first(),
            'success_rate' => $grRecords->count() > 0 
                ? round(($grRecords->where('success', true)->count() / $grRecords->count()) * 100, 2) 
                : 0
        ];
    }

    /**
     * Get average response time
     */
    public static function getAverageResponseTime($filters = [])
    {
        $query = self::where('success', true)
            ->whereNotNull('response_time_ms');

        if (isset($filters['po_no'])) {
            $query->where('po_no', $filters['po_no']);
        }

        if (isset($filters['plant'])) {
            $query->where('plant', $filters['plant']);
        }

        if (isset($filters['start_date']) && isset($filters['end_date'])) {
            $query->whereBetween('date_gr', [$filters['start_date'], $filters['end_date']]);
        }

        return $query->avg('response_time_ms');
    }
}