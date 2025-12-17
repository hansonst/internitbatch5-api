<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\DataTimbanganController;
use App\Http\Controllers\ProductionReportController;
use App\Http\Controllers\ChangelogsController;
use App\Http\Controllers\SapGrController;
use App\Http\Controllers\SapLoginController;
use App\Http\Controllers\SapManagementController;

// Health check route
Route::get('health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now(),
        'service' => 'Production Order API'
    ]);
});

// Public routes (no authentication required)
Route::post('/check-active-shift-with-perbox', [DataTimbanganController::class, 'checkActiveShiftWithPerbox']);
Route::get('/data-timbangan-perbox/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);
Route::get('/pro-orders', [ProductionOrderController::class, 'getProOrders']);

// ============= PRODUCTION ORDER AUTHENTICATION (PUBLIC) =============
Route::post('/login', [LoginController::class, 'login'])->name('login');

Route::prefix('sap')->group(function () {
    // Public Authentication route
    Route::post('/login', [SapLoginController::class, 'login']);
    Route::post('/login-rfid', [SapLoginController::class, 'loginWithRfid']);
    
    // Protected routes - require authentication
    Route::middleware('auth:sanctum_sap')->group(function () {
        // Auth management
        Route::post('/logout', [SapLoginController::class, 'logout']);
        Route::post('/logout-all', [SapLoginController::class, 'logoutAll']);
        Route::get('/profile', [SapLoginController::class, 'profile']);
        Route::get('/check-auth', [SapLoginController::class, 'checkAuth']);
        
        // Purchase Order routes - requires po_no parameter
        Route::get('/purchase-orders', [SapGrController::class, 'getPurchaseOrder']);
        
        // Good Receipt routes
        Route::post('/good-receipts', [SapGrController::class, 'createGoodReceipt']);
         Route::get('/gr-history', [SapGrController::class, 'getGrHistory']);
    
    // Optional: Get history for specific item only
    Route::get('/gr-history-by-item', [SapGrController::class, 'getGrHistoryByItem']);
    
    // Optional: Get unique dropdown values only (lighter response)
    Route::get('/gr-dropdown-values', [SapGrController::class, 'getGrDropdownValues']);
     Route::prefix('users')->group(function () {
        Route::get('/', [SapManagementController::class, 'index']);
        Route::post('/', [SapManagementController::class, 'store']);
        Route::put('/{userId}', [SapManagementController::class, 'update']);
        Route::put('/{userId}/change-password', [SapManagementController::class, 'changePassword']);
        Route::patch('/{userId}/deactivate', [SapManagementController::class, 'deactivate']);
    });
    });
});

// ============= PRODUCTION ORDER PROTECTED ROUTES (auth:sanctum) =============
Route::middleware('auth:sanctum')->group(function () {
    
    // ============= AUTHENTICATION ROUTES =============
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/profile', [LoginController::class, 'profile']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    // ============= PRODUCTION ORDERS ROUTES =============
    Route::prefix('production-orders')->group(function () {
        Route::get('/', [ProductionOrderController::class, 'index']);
        Route::post('/', [ProductionOrderController::class, 'store']);
        
        // ✅ Fetch batches by order ID - MUST BE BEFORE parameterized routes
        Route::get('/batches', [ProductionOrderController::class, 'getBatchesByOrderId']);
        
        // Specific routes (must come before parameterized routes)
        Route::get('/unassigned', [ProductionOrderController::class, 'getUnassignedOrders']);
        Route::get('/group/{groupCode}', [ProductionOrderController::class, 'getOrdersByGroup']);
        
        // Parameterized routes (come last)
        Route::get('/{batchNumber}', [ProductionOrderController::class, 'show']);
        Route::put('/{batchNumber}', [ProductionOrderController::class, 'update']);
        Route::delete('/{batchNumber}', [ProductionOrderController::class, 'destroy']);
        Route::patch('/{batchNumber}/close', [ProductionOrderController::class, 'close']);
        Route::put('/{batchNumber}/assign-group', [ProductionOrderController::class, 'assignGroup']);
        Route::patch('/{batchNumber}/remove-group', [ProductionOrderController::class, 'removeGroup']);
        Route::post('/{batchNumber}/start-shift', [ProductionOrderController::class, 'startShift']);
    });

    // ============= DATA TIMBANGAN ROUTES =============
    Route::prefix('data-timbangan')->group(function () {
        // Shift management
        Route::post('/start-shift', [DataTimbanganController::class, 'startShift']);
        Route::get('/active-shift', [DataTimbanganController::class, 'getActiveShift']);
        Route::post('/end-shift', [DataTimbanganController::class, 'endShift']);
        Route::get('/active-session', [DataTimbanganController::class, 'getActiveSession']);
        Route::get('/current-session', [DataTimbanganController::class, 'getCurrentSessionData']);
        
        // Weighing sessions
        Route::post('/sessions/start', [DataTimbanganController::class, 'startSession']);
        Route::put('/sessions/{id}/close', [DataTimbanganController::class, 'closeSession']);
        Route::get('/sessions/{id}', [DataTimbanganController::class, 'getSession']);
        Route::get('/weighing-sessions/active/{batchNumber}', [DataTimbanganController::class, 'getActiveSessionByBatch']);
        
        // Weighing entries
        Route::post('/weigh-box', [DataTimbanganController::class, 'addWeightEntry']);
        Route::post('/save-weighing-entry', [DataTimbanganController::class, 'saveWeighingEntry']);
        Route::get('/weighing-entries/session/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);
        Route::delete('/weighing-entries/{boxId}', [DataTimbanganController::class, 'deleteBox']);
        Route::put('/weighing-entries/{boxId}', [DataTimbanganController::class, 'updateWeightEntry']);
        
        // Batch-specific routes
        Route::get('/batch-session-status/{batchNumber}', [DataTimbanganController::class, 'checkBatchSessionStatus']);
        Route::get('/{batchNumber}/check-shift', [DataTimbanganController::class, 'checkShiftStatus']);
        Route::get('/{batchNumber}/current-session', [DataTimbanganController::class, 'getCurrentSession']);
        
        // MQTT functionality
        Route::get('/mqtt/latest-weight', [DataTimbanganController::class, 'getLatestMqttWeight']);
        Route::post('/mqtt/save-weight', [DataTimbanganController::class, 'saveMqttWeight']);
        
        // Per-box data
        Route::get('/perbox/{data_timbangan_id}', [DataTimbanganController::class, 'getWeightEntries']);
        
        // General index
        Route::get('/', [DataTimbanganController::class, 'index']);
    });
    
    // Update per-box data (backward compatibility)
    Route::put('/data-timbangan-perbox/{boxId}', [DataTimbanganController::class, 'updateWeightEntry']);

    // ============= CHANGELOGS ROUTES =============
    Route::prefix('changelogs')->group(function () {
        // Basic CRUD
        Route::get('/', [ChangelogsController::class, 'index']);
        Route::post('/', [ChangelogsController::class, 'store']);
        Route::get('/show/{id}', [ChangelogsController::class, 'show']);
        
        // Filtered queries
        Route::get('/batch/{batchNumber}', [ChangelogsController::class, 'getByBatch']);
        Route::get('/user/{userNik}', [ChangelogsController::class, 'getByUser']);
        
        // Analytics & Statistics
        Route::get('/analytics/statistics', [ChangelogsController::class, 'getStatistics']);
        Route::get('/analytics/user-activity', [ChangelogsController::class, 'getUserActivitySummary']);
        Route::get('/analytics/recent-activity', [ChangelogsController::class, 'getRecentActivity']);
        
        // Utilities
        Route::get('/meta/action-types', [ChangelogsController::class, 'getActionTypes']);
        Route::post('/search', [ChangelogsController::class, 'search']);
        Route::get('/export', [ChangelogsController::class, 'export']);
        
        // Maintenance (admin only - add middleware if needed)
        Route::delete('/maintenance/old-logs', [ChangelogsController::class, 'deleteOldLogs']);
    });

    // ============= OPERATOR GROUPS ROUTES =============
    Route::get('/operator-groups', [ProductionOrderController::class, 'getGroups']);

    // ============= DROPDOWN DATA ROUTES =============
    Route::prefix('dropdowns')->group(function () {
        Route::get('/materials', [ProductionOrderController::class, 'getMaterials']);
        Route::get('/machines', [ProductionOrderController::class, 'getMachines']);
        Route::get('/groups', [ProductionOrderController::class, 'getGroups']);
    });

    // ============= PRODUCTION REPORT ROUTES =============
    Route::prefix('production-report')->group(function () {
        Route::get('/', [ProductionReportController::class, 'index']);
        Route::get('/summary', [ProductionReportController::class, 'getProductionReportSummary']);
        Route::get('/filter-options', [ProductionReportController::class, 'getProductionReportFilterOptions']);
    });
});
