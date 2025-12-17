<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\GoodReceipt;
use App\Models\SapActivityLog;
use Exception;

class SapGrController extends Controller
{
    protected function logSapActivity(
        $activityType,
        $request,
        $success,
        $responseData = null,
        $statusCode = null,
        $errorMessage = null,
        $responseTime = null,
        $sapEndpoint = null,
        $rfidUser = null
    ) {
        try {
            $user = $rfidUser ?? $request->user();
            
            // Extract business references from request
            $requestData = $request->all();
            
            return SapActivityLog::create([
                'activity_type' => $activityType,
                'action' => $this->getActionFromActivityType($activityType),
                'user_id' => $user ? $user->user_id : null,
                'users_table_id' => $user ? $user->id : null,
                'user_email' => $user ? $user->email : null,
                'first_name' => $user ? $user->first_name : null,
                'last_name' => $user ? $user->last_name : null,
                'full_name' => $user ? $user->full_name : null,
                'jabatan' => $user ? $user->jabatan : null,
                'department' => $user ? $user->department : null,
                'ip_address' => $request->ip(),
                'po_no' => $requestData['po_no'] ?? null,
                'item_po' => $requestData['item_po'] ?? null,
                'dn_no' => $requestData['dn_no'] ?? null,
                'material_doc_no' => $responseData['mat_doc'] ?? $responseData['material_doc_no'] ?? null,
                'plant' => $requestData['plant'] ?? null,
                'request_payload' => $requestData,
                'response_data' => $responseData,
                'success' => $success,
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'response_time_ms' => $responseTime,
                'sap_endpoint' => $sapEndpoint
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to log SAP activity', [
                'error' => $e->getMessage(),
                'activity_type' => $activityType
            ]);
            return null;
        }
    }

    /**
     * Get action name from activity type
     */
    private function getActionFromActivityType($activityType)
    {
        $actions = [
            'get_po' => 'view',
            'create_gr' => 'create',
            'get_gr_summary' => 'view',
            'get_po_list' => 'view',
            'cancel_gr' => 'delete',
            'update_gr' => 'update',
            'get_gr_history' => 'view',
            'get_gr_history_by_item' => 'view',
            'get_gr_dropdown_values' => 'view'
        ];

        return $actions[$activityType] ?? 'unknown';
    }

    private $baseUrl;
    private $username;
    private $password;
    private $sapClient;

    public function __construct()
    {
        $this->baseUrl = env('SAP_BASE_URL');
        $this->username = env('SAP_USERNAME');
        $this->password = env('SAP_PASSWORD');
        $this->sapClient = env('SAP_CLIENT');
    }

    /**
     * Get Purchase Order details - requires PO number input
     * 
     * @param Request $request  
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPurchaseOrder(Request $request)
    {
        // Validate that po_no is provided
        $validated = $request->validate([
            'po_no' => 'required|string'
        ]);

        $poNo = $validated['po_no'];
        
        // Get authenticated user
        $user = $request->user();
        $startTime = microtime(true);
        
        Log::info('=== GET PURCHASE ORDER START ===', [
            'po_no' => $poNo,
            'po_length' => strlen($poNo),
            'po_type' => gettype($poNo),
            'auth_user' => $user ? ($user->user_id ?? $user->id) : 'PUBLIC',
            'user_email' => $user ? ($user->email ?? 'N/A') : 'PUBLIC'
        ]);

        try {
            $url = "{$this->baseUrl}/sap/opu/odata4/sap/zmm_oji_po_bind/srvd/sap/zmm_oji_po/0001/ZPOA_DTL_LIST(po_no='{$poNo}')/Set";

            Log::info('Making request to SAP', [
                'url' => $url,
                'username' => $this->username,
                'requested_by' => $user ? ($user->email ?? $user->user_id ?? 'N/A') : 'PUBLIC'
            ]);

            $response = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'sap-client' => $this->sapClient
                ])
                ->withOptions([
                    'verify' => false 
                ])
                ->get($url);

            Log::info('SAP Response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response_body' => $response->json(),
                'item_count' => count($response->json()['value'] ?? [])
            ]);

            if ($response->successful()) {
                $responseTime = round((microtime(true) - $startTime) * 1000);
                $responseData = $response->json();
                
                if (empty($responseData['value'])) {
                    Log::warning('⚠️ SAP returned empty items array', [
                        'po_no' => $poNo,
                        'full_response' => $responseData
                    ]);
                }
                
                $this->logSapActivity('get_po', $request, true, $responseData, $response->status(), null, $responseTime, $url);
                
                Log::info('=== GET PURCHASE ORDER END (SUCCESS) ===', [
                    'items_returned' => count($responseData['value'] ?? [])
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Purchase order retrieved successfully',
                    'data' => $responseData
                ]);
            }

            Log::warning('SAP request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            $responseTime = round((microtime(true) - $startTime) * 1000);
            $this->logSapActivity('get_po', $request, false, null, $response->status(), $response->body(), $responseTime, $url);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch PO data',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000);
            $this->logSapActivity('get_po', $request, false, null, 500, $e->getMessage(), $responseTime, $url ?? null);
            
            Log::error('Exception in getPurchaseOrder', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching PO data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Good Receipt Entry - UPDATED to store both RFIDs
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createGoodReceipt(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'id_card' => 'required|string|min:10',  // RFID for posting (minimum 10 digits)
            'dn_no' => 'required|string',  
            'doc_date' => 'required|date_format:d-m-Y', 
            'post_date' => 'required|date_format:d-m-Y',
            
            'items' => 'required|array|min:1',
            'items.*.po_no' => 'required|string',
            'items.*.item_po' => 'required|string',
            'items.*.qty' => 'required|numeric|min:0.01',  
            'items.*.plant' => 'required|string',
            'items.*.sloc' => 'nullable|string',
            'items.*.batch_no' => 'nullable|string',
            'items.*.dom' => 'nullable|string'
        ]);

        // 🎯 STEP 1: Get logged-in user (from session/token)
        $loggedInUser = $request->user();
        
        if (!$loggedInUser) {
            return response()->json([
                'success' => false,
                'message' => 'No authenticated user found'
            ], 401);
        }

        // 🎯 STEP 2: Verify RFID card that was tapped for posting
        $rfidVerification = $this->verifyRfidCard($validated['id_card']);
        
        if (!$rfidVerification['valid']) {
            Log::warning('GR Post blocked - Invalid RFID', [
                'tapped_rfid' => $validated['id_card'],
                'reason' => $rfidVerification['message'],
                'logged_in_user' => $loggedInUser->user_id,
                'logged_in_rfid' => $loggedInUser->id_card ?? 'N/A'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $rfidVerification['message']
            ], 403);
        }
        
        $rfidUser = $rfidVerification['user']; // User who tapped the card

        // 🎯 STEP 3: Extract RFID values
        $loggedInUserRfid = $loggedInUser->id_card ?? $loggedInUser->rfid ?? null;
        $tappedRfid = $validated['id_card']; // The RFID that was tapped

        // Validate logged-in user has RFID
        if (empty($loggedInUserRfid)) {
            Log::error('Logged-in user has no RFID', [
                'user_id' => $loggedInUser->user_id,
                'email' => $loggedInUser->email
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Your account does not have an RFID registered. Please contact administrator.'
            ], 400);
        }

        // 🎯 STEP 4: Log the posting details
        Log::info('=== CREATE GOOD RECEIPT START ===', [
            'logged_in_user_id' => $loggedInUser->user_id,
            'logged_in_user_name' => $loggedInUser->full_name ?? $loggedInUser->user_id,
            'logged_in_user_rfid' => $loggedInUserRfid,
            
            'tapped_rfid' => $tappedRfid,
            'rfid_user_id' => $rfidUser->user_id,
            'rfid_user_name' => $rfidUser->full_name ?? $rfidUser->user_id,
            
            'same_person' => ($loggedInUserRfid === $tappedRfid) ? 'YES' : 'NO',
            'items_count' => count($validated['items']),
            'dn_no' => $validated['dn_no']
        ]);

        $docDate = \Carbon\Carbon::createFromFormat('d-m-Y', $validated['doc_date'])->format('Y-m-d');
        $postDate = \Carbon\Carbon::createFromFormat('d-m-Y', $validated['post_date'])->format('Y-m-d');
        
        $startTime = microtime(true);

        try {
            $url = "{$this->baseUrl}/zapi/ZAPI/OJI_GR_ENTRY?sap-client={$this->sapClient}";

            // Build it_input array for SAP API
            $itInputArray = [];
            $goodReceiptRecords = [];

            foreach ($validated['items'] as $item) {
                // Prepare SAP payload item
                $itInputArray[] = [
                    'po_no' => $item['po_no'],
                    'item_po' => str_pad($item['item_po'], 5, '0', STR_PAD_LEFT),
                    'qty' => $item['qty'],
                    'plant' => $item['plant'],
                    'sloc' => $item['sloc'] ?? '',
                    'batch_no' => $item['batch_no'] ?? '',
                    'dom' => !empty($item['dom']) ? \Carbon\Carbon::createFromFormat('d-m-Y', $item['dom'])->format('Y-m-d') : $docDate
                ];

                // 🎯 CRITICAL: Create DB record with BOTH RFIDs
                $goodReceiptRecords[] = GoodReceipt::create([
                    'dn_no' => $validated['dn_no'],
                    'date_gr' => $postDate,
                    'posting_date' => $postDate,
                    'po_no' => $item['po_no'],
                    'item_po' => $item['item_po'],
                    'qty' => $item['qty'],
                    'plant' => $item['plant'],
                    'sloc' => $item['sloc'] ?? null,
                    'batch_no' => $item['batch_no'] ?? null,
                    'success' => false,
                    'error_message' => 'Processing...',
                    
                    // 🎯 Store user info (for backward compatibility)
                    'user_id' => $rfidUser->user_id,
                    'users_table_id' => $rfidUser->id,
                    'user_email' => $loggedInUser->email,
                    'department' => $loggedInUser->department ?? $rfidUser->department,
                    
                    // 🎯 NEW: Store BOTH RFIDs
                    'logged_in_user_id' => $loggedInUserRfid,  // ✅ RFID of logged-in account
                    'rfid_user_id' => $tappedRfid,             // ✅ RFID tapped for posting
                    
                    'sap_request' => $item,
                    'sap_endpoint' => $url
                ]);
            }

            $payload = [
                'dn_no' => $validated['dn_no'] ?? '',
                'doc_date' => $docDate,   
                'post_date' => $postDate, 
                'it_input' => $itInputArray
            ];

            Log::info('GR Payload (Batch)', [
                'items_count' => count($itInputArray),
                'payload' => $payload
            ]);

            // Send to SAP
            $response = Http::withBasicAuth($this->username, $this->password)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ])
                ->withOptions(['verify' => false])
                ->post($url, $payload);

            Log::info('SAP GR Response received', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response_body' => $response->body()
            ]);

            if ($response->successful()) {
                $responseTime = round((microtime(true) - $startTime) * 1000);
                $sapData = $response->json();

                // Update all DB records with success
                foreach ($goodReceiptRecords as $record) {
                    $record->update([
                        'success' => true,
                        'error_message' => null,
                        'material_doc_no' => $sapData['mat_doc'] ?? $sapData['material_doc_no'] ?? null,
                        'doc_year' => $sapData['doc_year'] ?? $sapData['year'] ?? null,
                        'posting_date' => $sapData['posting_date'] ?? $postDate,
                        'sap_response' => $sapData,
                        'response_time_ms' => $responseTime
                    ]);
                }

                $this->logSapActivity(
                    'create_gr', 
                    $request, 
                    true, 
                    $sapData, 
                    $response->status(), 
                    null, 
                    $responseTime, 
                    $url,
                    $rfidUser
                );

                Log::info('=== CREATE GOOD RECEIPT END (SUCCESS) ===', [
                    'items_processed' => count($goodReceiptRecords),
                    'material_doc_no' => $sapData['mat_doc'] ?? 'N/A',
                    'logged_in_rfid' => $loggedInUserRfid,
                    'posted_by_rfid' => $tappedRfid
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Good Receipt created successfully',
                    'data' => $sapData,
                    'meta' => [
                        'items_processed' => count($goodReceiptRecords),
                        'logged_in_user' => $loggedInUser->user_id,
                        'posted_by_rfid' => $tappedRfid
                    ]
                ]);
            }

            // Handle failure
            Log::warning('SAP GR request failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            $responseTime = round((microtime(true) - $startTime) * 1000);

            foreach ($goodReceiptRecords as $record) {
                $record->update([
                    'success' => false,
                    'error_message' => $response->body(),
                    'sap_response' => ['error' => $response->body()],
                    'response_time_ms' => $responseTime
                ]);
            }

            $this->logSapActivity(
                'create_gr', 
                $request, 
                false, 
                null, 
                $response->status(), 
                $response->body(), 
                $responseTime, 
                $url,
                $rfidUser
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed to create Good Receipt',
                'error' => $response->body()
            ], $response->status());

        } catch (Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000);

            // Update all pending records with error
            foreach ($goodReceiptRecords as $record) {
                $record->update([
                    'success' => false,
                    'error_message' => $e->getMessage(),
                    'response_time_ms' => $responseTime
                ]);
            }

            $this->logSapActivity(
                'create_gr', 
                $request, 
                false, 
                null, 
                500, 
                $e->getMessage(), 
                $responseTime, 
                $url ?? null,
                $rfidUser
            );

            Log::error('Exception in createGoodReceipt', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error creating Good Receipt',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getGrHistoryByItem(Request $request)
    {
        $validated = $request->validate([
            'po_no' => 'required|string',
            'item_po' => 'required|string'
        ]);

        $poNo = $validated['po_no'];
        $itemPo = $validated['item_po'];
        $user = $request->user();

        Log::info('=== GET GR HISTORY BY ITEM START ===', [
            'po_no' => $poNo,
            'item_po' => $itemPo,
            'requested_by' => $user ? ($user->email ?? $user->user_id ?? 'N/A') : 'PUBLIC'
        ]);

        try {
            $grRecords = GoodReceipt::where('po_no', $poNo)
                ->where('item_po', $itemPo)
                ->where('success', true)
                ->whereNotNull('material_doc_no')
                ->orderBy('date_gr', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($record) {
                    return [
                        'date_gr' => $record->date_gr,
                        'qty' => $record->qty,
                        'dn_no' => $record->dn_no,
                        'sloc' => $record->sloc,
                        'batch_no' => $record->batch_no ?? '',
                        'mat_doc' => $record->material_doc_no,
                        'doc_year' => $record->doc_year,
                        'plant' => $record->plant,
                        'created_at' => $record->created_at->format('Y-m-d H:i:s'),
                        'created_by' => $record->user_email ?? 'Unknown',
                        'department' => $record->department,
                        'logged_in_rfid' => $record->logged_in_user_id,
                        'posted_by_rfid' => $record->rfid_user_id
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'GR history retrieved successfully',
                'data' => $grRecords,
                'meta' => [
                    'po_no' => $poNo,
                    'item_po' => $itemPo,
                    'total_records' => $grRecords->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Exception in getGrHistoryByItem', [
                'po_no' => $poNo,
                'item_po' => $itemPo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching GR history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getGrDropdownValues(Request $request)
    {
        $validated = $request->validate([
            'po_no' => 'required|string',
            'item_po' => 'nullable|string'
        ]);

        $poNo = $validated['po_no'];
        $itemPo = $validated['item_po'] ?? null;

        try {
            $query = GoodReceipt::where('po_no', $poNo)
                ->where('success', true)
                ->whereNotNull('material_doc_no');

            if ($itemPo) {
                $query->where('item_po', $itemPo);
            }

            $records = $query->get();

            $dnNos = $records->pluck('dn_no')->unique()->filter()->sort()->values();
            $dateGrs = $records->pluck('date_gr')->unique()->filter()->sort()->values();
            $batchNos = $records->pluck('batch_no')->unique()->filter()->reject(function($value) {
                return empty($value);
            })->sort()->values();
            $slocs = $records->pluck('sloc')->unique()->filter()->sort()->values();

            return response()->json([
                'success' => true,
                'message' => 'Dropdown values retrieved successfully',
                'data' => [
                    'dn_no' => $dnNos,
                    'date_gr' => $dateGrs,
                    'batch_no' => $batchNos,
                    'sloc' => $slocs
                ],
                'meta' => [
                    'po_no' => $poNo,
                    'item_po' => $itemPo,
                    'total_records' => $records->count()
                ]
            ]);

        } catch (Exception $e) {
            Log::error('Exception in getGrDropdownValues', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error fetching dropdown values',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify RFID card exists and is active
     */
    private function verifyRfidCard($idCard)
    {
        $user = \App\Models\UserSap::where('id_card', $idCard)->first();
        
        if (!$user) {
            return [
                'valid' => false,
                'message' => 'RFID card not registered in system'
            ];
        }
        
        if (!$user->isActive()) {
            return [
                'valid' => false,
                'message' => 'User account is not active'
            ];
        }
        
        return [
            'valid' => true,
            'user' => $user
        ];
    }
}