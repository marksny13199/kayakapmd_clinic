<?php

namespace App\Http\Controllers;

use App\Models\ConsultationModel;
use App\Models\EnlistmentModel;
use App\Models\HMOModel;
use App\Models\PatientMasterlist;
use App\Models\PCBModel;
use App\Models\PhilHealthDiagModel;
use App\Models\PhilHealthMedsModel;
use App\Models\SOAPModel;
use App\Models\Stocks\StocksLedgerModel;
use App\Models\Stocks\StocksListingModel;
use App\Models\Stocks\StocksGroupingModel;
use App\Models\StocksLedger;
use App\Models\XmlTransModel;
use App\Services\Admin\AdminService;
use Date;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\ChargesCategoryModel;
use App\Models\ChargesModel;
use App\Models\DiagnosticsCategoryModel;
use App\Models\DiagnosticsMasterlistModel;
use App\Models\DocChargesModel;
use App\Models\DoctorModel;
use App\Models\DoctorsProfileModel;
use App\Models\MedicineMasterlistModel;
use App\Models\MedicineModel;
use App\Models\KayakapProfileModel;
use App\Models\PatientClassificationModel;
use App\Models\SecretaryDoctorsModel;
use App\Models\SecretaryModel;
use App\Models\SettlementsModel;
use App\Models\AdminModel;
use App\Models\ThemeModel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Log;

class ManagementController extends Controller
{
    protected $service;

    public function __construct(AdminService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return redirect()->route('admin.dashboard');
    }

    // --- Page-serving methods ---

    public function dashboardPage()
    {
        $data = $this->getDashboardData();
        return view('pages.admin.dashboard', ['data' => $data]);
    }

    public function profilePage()
    {
        return view('pages.admin.profile');
    }

    /**
     * Detailed Comment: Serves the Admin Theme Settings customization page,
     * providing current active theme configuration and facility profile data.
     */
    public function themeSettingsPage()
    {
        $profile = KayakapProfileModel::first();
        $theme = ThemeModel::getActiveTheme($profile?->clientcode);
        return view('pages.admin.settings.theme', compact('theme', 'profile'));
    }

    /**
     * Detailed Comment: Renders the Admin Patient Classification Settings view.
     * Allows clinic administrators to manage clinical classifications, dynamic sub-field metadata, and toggles.
     */
    public function patientClassificationPage()
    {
        return view('pages.admin.settings.patient_classification');
    }

    public function secretariesPage()
    {
        return view('pages.admin.users.secretaries');
    }

    // Detailed Comment: Page-serving method for Secretaries/Admin Users management screen
    public function secretariesAdminsPage()
    {
        return view('pages.admin.users.secretaries');
    }

    public function doctorsPage()
    {
        return view('pages.admin.users.doctors');
    }

    public function secretaryPanelPage()
    {
        $doctors = DoctorsProfileModel::select(['docrefno', 'docname', 'specializations', 'expertise'])
            ->get();

        $secretaries = SecretaryModel::select(['secrefno', 'seclname'])
            ->get();

        // lib_hci_class has no status column, so load its classification definitions in display order.
        $patientClassifications = PatientClassificationModel::orderBy('CLASS_DEF')->get();

        return view('pages.secretary.queue', [
            'doctors' => $doctors,
            'secretaries' => $secretaries,
            'patientClassifications' => $patientClassifications
        ]);
    }

    public function hmoPanelPage()
    {
        return view('pages.admin.hmo');
    }

    public function diagnosticRequestsPage()
    {
        return view('pages.admin.consultations.requests');
    }

    public function billingsPage()
    {
        return view('pages.admin.consultations.billing');
    }

    public function settlementsPage()
    {
        return view('pages.admin.consultations.settlements');
    }

    public function stocksManagementPage()
    {
        return view('pages.admin.stocks.management');
    }

    /**
     * Detailed Comment: Serves the standalone Grouping Management page under Stocks & Services.
     * Manages category-specific grouping options stored in stocks_groupings.
     */
    public function stocksGroupingsPage()
    {
        return view('pages.admin.stocks.groupings');
    }

    public function stocksLedgerPage()
    {
        return view('pages.admin.stocks.ledger');
    }

    public function inventoryPage()
    {
        return view('pages.admin.stocks.inventory');
    }




    // public function diagnosticCategoryPage()
    // {
    //     return view('pages.admin.diagnostics.category');
    // }

    // public function medicinesPage()
    // {
    //     return view('pages.admin.medicines');
    // }

    // public function chargesMasterlistPage()
    // {
    //     return view('pages.admin.charges.masterlist');
    // }

    // public function chargesCategoryPage()
    // {
    //     return view('pages.admin.charges.category');
    // }

    public function philhealthOverviewPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.overview');
    }

    public function philhealthManagementPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.management');
    }


    public function philhealthUploadingPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.uploading');
    }

    public function philhealthToolsPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.tools');
    }

    public function philhealthConsultationsPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.consultations');
    }

    public function philhealthMasterlistPage()
    {
        if (!config('services.philhealth.enabled', true)) {
            abort(403, 'PhilHealth integration is currently disabled in system settings.');
        }
        return view('pages.admin.philhealth.masterlist');
    }

    public function chargeCatgPage()
    {
        return view('pages.admin.utilities.chargecat');
    }

    public function diagCatgPage()
    {
        return view('pages.admin.utilities.diagcat');
    }

    public function reportsPage()
    {
        return view('pages.admin.philhealth.reports');
    }

    public function getDashboardData(?Request $request = null)
    {
        $data = [];
        $data['patient_records'] = PatientMasterlist::all()->count();
        $data['consultations'] = ConsultationModel::all()->count();
        $data['today_consultations'] = ConsultationModel::whereDate('consultation_date', now())->count();

        return $data;
    }

    // Stocks related
    public function fetchStocks(Request $request)
    {
        $stocks = $this->service->getStockList($request->all());

        return response()->json($stocks);
    }

    /**
     * Detailed Comment: Fetches generic drug references from dw_lib_meds_generic for searchable dropdowns.
     * Formats output for Select2 AJAX compatibility (`results`) and retains legacy `generic` array.
     */
    public function fetchDrugRef(Request $request)
    {
        $term = $request->input('term') ?: $request->input('q');
        $data = $this->service->getDrugRefs($term);

        $results = [];
        if (!empty($data['generic'])) {
            foreach ($data['generic'] as $item) {
                $results[] = [
                    'id' => $item->value,
                    'text' => $item->label,
                    'gen_code' => $item->id,
                    'gen_desc' => $item->value
                ];
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'generic' => $data['generic'] ?? []
        ]);
    }

    public function fetchDiagRef(Request $request)
    {
        $data = $this->service->getDiagsRefs($request->input('term'));

        return response()->json($data);
    }

    public function fetchStocksLedger(Request $request)
    {
        $query = StocksLedgerModel::query();

        $start = $request->input('start');
        $limit = $request->input('length');
        $search = $request->input('search.value');

        if ($search) {
            $query->select(['patient_name', 'item_dscr', 'dispensed', 'updated', 'dispensed_status'])
                ->where(function ($q) use ($search) {
                    $q->where('patient_name', 'LIKE', "%{$search}%")
                        ->orWhere('item_dscr', 'LIKE', "%{$search}%")
                        ->orWhere('dispensed_status', 'LIKE', "%{$search}%")
                        ->orWhere('updated', 'LIKE', "%{$search}%");
                });
        }

        $recordsFiltered = $query->count();
        $recordsTotal = StocksLedgerModel::count();

        $records = $query->offset($start)->limit($limit)->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records
        ]);
    }

    public function fetchStockItem(Request $request)
    {
        $item = StocksListingModel::where(['prodcode' => $request->prodcode])->first();

        if ($item) {
            return response()->json(['success' => true, 'item' => $item]);
        }

        return response()->json(['success' => false]);
    }

    public function saveStockItem(Request $request)
    {
        $item = [
            'item_grouping' => $request->item_group,
            'prodcode' => 'PROD' . now()->format('mdYHis'),
            'prod_itemdscr' => $request->item_dscr,
            'price_regular' => $request->price_regular,
            'price_phic' => $request->price_phic,
            'price_hmo' => $request->price_hmo,
            'price_others' => $request->price_others,
            'is_inventory' => $request->is_inventory == "on" ? true : false,
            'qty' => $request->item_quantity
        ];

        $add_array = [];

        // Detailed Comment: Dosage Form handling (supporting standard dropdown items and custom user input)
        $dosageForm = $request->input('dosage_form');
        if ($dosageForm === 'Custom') {
            $dosageForm = $request->input('custom_dosage_form');
        }

        // Detailed Comment: PhilHealth Gamot Essential tinyint flag
        $pge = $request->boolean('philhealth_gamot_essential') || $request->input('philhealth_gamot_essential') == '1' ? 1 : 0;
        $group = $request->input('drug_group') ?? $request->input('group_name') ?? $request->input('drug_grouping');

        if ($request->item_group == "DRUGS AND MEDS") {
            $add_array = [
                'phic_reference_code' => $request->ref_code,
                'drug_generic' => $request->drug_generic,
                'drug_brand' => $request->drug_brand,
                'drug_dosage' => $request->drug_dosage,
                'drug_grouping' => $group,
                'dosage_form' => $dosageForm,
                'philhealth_gamot_essential' => $pge
            ];
        } else if ($request->item_group == "DIAGNOSTIC") {
            $add_array = [
                'phic_reference_code' => $request->ref_code,
                'drug_grouping' => $group
            ];
        } else if ($request->item_group == "IMAGING" || !empty($group)) {
            $add_array = [
                'drug_grouping' => $group
            ];
        }

        if (!empty($add_array)) {
            $item = array_merge($item, $add_array);
        }

        $result = StocksListingModel::create($item);

        if ($result) {
            Log::info('Admin created new stock item', [
                'prodcode' => $item['prodcode'],
                'item_grouping' => $item['item_grouping'],
                'drug_grouping' => $group,
                'dosage_form' => $dosageForm,
                'pge' => $pge
            ]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function editStockItem(Request $request)
    {
        $data = [
            'prod_itemdscr' => $request->eitem_dscr,
            'item_grouping' => $request->eitem_group,
            'price_regular' => $request->eprice_regular,
            'price_phic' => $request->eprice_phic,
            'price_hmo' => $request->eprice_hmo,
            'price_others' => $request->eprice_others
        ];

        $add_array = [];

        // Detailed Comment: Dosage Form handling for edit form
        $edosageForm = $request->input('edosage_form') ?? $request->input('dosage_form');
        if ($edosageForm === 'Custom') {
            $edosageForm = $request->input('ecustom_dosage_form') ?? $request->input('custom_dosage_form');
        }

        $epge = $request->boolean('ephilhealth_gamot_essential') || $request->boolean('philhealth_gamot_essential') || $request->input('ephilhealth_gamot_essential') == '1' || $request->input('philhealth_gamot_essential') == '1' ? 1 : 0;
        $egroup = $request->input('edrug_group') ?? $request->input('drug_group') ?? $request->input('group_name') ?? $request->input('drug_grouping');

        if ($request->eitem_group == "DRUGS AND MEDS") {
            $add_array = [
                'phic_reference_code' => $request->eref_code,
                'drug_generic' => $request->edrug_generic,
                'drug_brand' => $request->edrug_brand,
                'drug_dosage' => $request->edrug_dosage,
                'drug_grouping' => $egroup,
                'dosage_form' => $edosageForm,
                'philhealth_gamot_essential' => $epge
            ];
        } else if ($request->eitem_group == "DIAGNOSTIC") {
            $add_array = [
                'phic_reference_code' => $request->eref_code,
                'drug_grouping' => $egroup
            ];
        } else if ($request->eitem_group == "IMAGING" || !empty($egroup)) {
            $add_array = [
                'drug_grouping' => $egroup
            ];
        }

        if (!empty($add_array)) {
            $data = array_merge($data, $add_array);
        }

        $item = StocksListingModel::where(['prodcode' => $request->prodcode])->update($data);

        if ($item) {
            Log::info('Admin updated stock item', [
                'prodcode' => $request->prodcode,
                'item_grouping' => $data['item_grouping'],
                'drug_grouping' => $egroup,
                'dosage_form' => $edosageForm,
                'pge' => $epge
            ]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteStockItem(Request $request)
    {
        $item = StocksListingModel::where(['prodcode' => $request->prodcode])->delete();

        if ($item) {
            Log::info('Admin deleted stock item', ['prodcode' => $request->prodcode]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    /**
     * Detailed Comment: Fetches category-specific grouping options from stocks_groupings table.
     * Supports DataTables server-side pagination or JSON list for dropdown selection.
     */
    public function fetchGroupings(Request $request)
    {
        $category = $request->input('category');
        $query = StocksGroupingModel::query();

        if (!empty($category) && $category !== 'ALL') {
            $query->where('category', $category);
        }

        if ($request->has('draw')) {
            $draw = intval($request->input('draw'));
            $start = intval($request->input('start', 0));
            $length = intval($request->input('length', 25));
            $search = $request->input('search.value');

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('group_name', 'like', "%{$search}%")
                      ->orWhere('category', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $recordsTotal = StocksGroupingModel::count();
            $recordsFiltered = (clone $query)->count();

            $data = $query->orderBy('category', 'asc')
                ->orderBy('group_name', 'asc')
                ->offset($start)
                ->limit($length)
                ->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data
            ]);
        }

        // Detailed Comment: If status column exists, filter by ACTIVE or null; otherwise return all groupings
        if (\Illuminate\Support\Facades\Schema::hasColumn('stocks_groupings', 'status')) {
            $query->where(function ($q) {
                $q->where('status', 'ACTIVE')->orWhereNull('status');
            });
        }

        $groupings = $query->orderBy('group_name', 'asc')->get();

        return response()->json([
            'success' => true,
            'groupings' => $groupings,
            'data' => $groupings
        ]);
    }

    /**
     * Detailed Comment: Creates a new category grouping entry in stocks_groupings table.
     */
    public function saveGrouping(Request $request)
    {
        $request->validate([
            'category' => 'required|string|max:80',
            'group_name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:ACTIVE,INACTIVE'
        ]);

        $groupCode = 'GRP' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $request->category), 0, 4)) . now()->format('ymdHis');

        $grouping = StocksGroupingModel::create([
            'group_code' => $groupCode,
            'category' => trim($request->category),
            'group_name' => trim($request->group_name),
            'description' => $request->description,
            'status' => $request->input('status', 'ACTIVE'),
            'created_by' => auth()->guard('admin')->user()->username ?? 'admin'
        ]);

        Log::info('New stock grouping created by admin', [
            'group_code' => $groupCode,
            'category' => $request->category,
            'group_name' => $request->group_name
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grouping created successfully.',
            'grouping' => $grouping
        ]);
    }

    /**
     * Detailed Comment: Updates an existing category grouping entry in stocks_groupings table.
     */
    public function updateGrouping(Request $request)
    {
        $request->validate([
            'group_name' => 'required|string|max:120',
            'category' => 'required|string|max:80',
        ]);

        $query = StocksGroupingModel::query();
        if ($request->filled('id')) {
            $query->where('id', $request->id);
        } elseif ($request->filled('group_code')) {
            $query->where('group_code', $request->group_code);
        } else {
            return response()->json(['success' => false, 'message' => 'Grouping ID or code required.'], 422);
        }

        $grouping = $query->first();
        if (!$grouping) {
            return response()->json(['success' => false, 'message' => 'Grouping not found.'], 404);
        }

        $grouping->update([
            'category' => trim($request->category),
            'group_name' => trim($request->group_name),
            'description' => $request->description ?? $grouping->description,
            'status' => $request->input('status', $grouping->status),
        ]);

        Log::info('Stock grouping updated by admin', [
            'id' => $grouping->id,
            'group_code' => $grouping->group_code,
            'group_name' => $grouping->group_name
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grouping updated successfully.'
        ]);
    }

    /**
     * Detailed Comment: Deletes an existing category grouping from stocks_groupings table.
     */
    public function deleteGrouping(Request $request)
    {
        $query = StocksGroupingModel::query();
        if ($request->filled('id')) {
            $query->where('id', $request->id);
        } elseif ($request->filled('group_code')) {
            $query->where('group_code', $request->group_code);
        } else {
            return response()->json(['success' => false, 'message' => 'Grouping ID or code required.'], 422);
        }

        $grouping = $query->first();
        if (!$grouping) {
            return response()->json(['success' => false, 'message' => 'Grouping not found.'], 404);
        }

        $grouping->delete();

        Log::info('Stock grouping deleted by admin', [
            'id' => $grouping->id,
            'group_code' => $grouping->group_code
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grouping deleted successfully.'
        ]);
    }

    public function fetchInventory(Request $request)
    {
        $query = StocksListingModel::query();

        $start = $request->input('start');
        $length = $request->input('length');
        $search = $request->input('search.value');

        if (!empty($search)) {
            $query->where('prod_itemdscr', 'LIKE', "%{$search}%");
        }

        $recordsTotal = StocksListingModel::count();

        $data = $query->select(['prodcode', 'prod_itemdscr', 'qty', 'item_grouping', 'is_inventory'])
            ->where(['is_inventory' => true])
            ->when($request->filter !== 'ALL', function ($q) use ($request) {
                return $q->where('item_grouping', $request->filter);
            })
            ->offset($start)
            ->limit($length)
            ->get();

        $recordsFiltered = $query->count();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsFiltered' => $recordsFiltered,
            'recordsTotal' => $recordsTotal,
            'data' => $data
        ]);
    }

    public function fetchDrugDetails(Request $request)
    {
        $generic = $request->input('generic');
        $refcode = $request->input('phic_reference_code');

        $query = DB::table('dw_lib_medicine as m')
            ->leftJoin('dw_lib_meds_generic as g', 'm.GEN_CODE', '=', 'g.gen_code')
            ->leftJoin('dw_lib_meds_strength as st', 'm.STRENGTH_CODE', '=', 'st.strength_code')
            ->select(
                'm.drug_code as drug_code',
                'm.drug_desc as drug_description',
                'g.gen_desc as generic_description',
                'st.strength_desc as strength_description'
            );

        if ($generic) {
            $result = $query
                ->where('g.gen_desc', 'LIKE', "%{$generic}%")
                ->first();
        } else if ($refcode) {
            $result = $query
                ->where('m.drug_code', $refcode)
                ->first();
        }

        if ($result) {
            return response()->json(['success' => true, 'drug' => $result]);
        }

        return response()->json(['success' => false]);
    }

    public function getAssigned(Request $request)
    {
        $assigned_doctors = SecretaryDoctorsModel::where([
            'secrefno' => $request->secrefno
        ])->pluck('docrefno');

        $doctors = DoctorsProfileModel::select(['docrefno', 'docname'])
            ->whereIn('docrefno', $assigned_doctors)
            ->get();

        return response()->json($doctors);
    }

    public function fetchDoctors()
    {
        $doctors = DoctorsProfileModel::all();

        return response()->json(['doctors' => $doctors]);
    }

    public function fetchDoctor(Request $request)
    {
        $doctor = DoctorsProfileModel::where('docrefno', $request->docrefno)->first();
        if ($doctor) {
            $rights = DoctorModel::where('docrefno', $request->docrefno)->first();
            if ($rights) {
                $doctor->username = $rights->username;
                $doctor->taxpercent = $rights->taxpercent;
                $doctor->bankacct = $rights->bankacct;
                $doctor->slcode = $rights->slcode;
            }
            $doctor->source_table = 'doctors & doctorsrights';
        }

        return response()->json(['success' => true, 'doctor' => $doctor]);
    }

    public function addDoctor(Request $request)
    {
        $request->validate([
            'docfname' => 'required|string',
            'doclname' => 'required|string',
            'pass' => 'required|string|min:5',
        ]);

        // Detailed Comment: Generate unique docrefno with timestamp and random entropy to prevent sub-second collision
        $docrefno = Date::now()->format('mdYHis') . rand(100, 999) . 'MD';
        $username = $request->filled('username') ? strtolower(trim($request->username)) : strtolower(trim($request->doclname));

        // Detailed Comment: Create doctor profile in the primary 'doctors' table with all columns
        $profile = DoctorsProfileModel::create([
            'doccode' => 'PFMD',
            'docrefno' => $docrefno,
            'docfname' => $request->docfname,
            'docmname' => $request->docmname,
            'doclname' => $request->doclname,
            'suffix' => $request->suffix,
            'titlename' => $request->titlename,
            'docfirst' => $request->docfirst ?? $request->docfname,
            'docname' => trim($request->docfname . ' ' . ($request->docmname ?? '') . ' ' . $request->doclname . ' ' . ($request->suffix ?? '')),
            'emailadd' => $request->emailadd,
            'cellno' => $request->cellno,
            'adrs' => $request->adrs,
            'proftype' => $request->proftype,
            'expertise' => (!empty($request->expertise) ? $request->expertise : (is_array($request->input('specializations')) ? implode(', ', $request->input('specializations')) : $request->expertise)),
            'specializations' => is_array($request->input('specializations')) ? array_values(array_filter($request->input('specializations'))) : ($request->filled('specializations') ? array_values(array_filter(explode(',', $request->input('specializations')))) : ($request->filled('expertise') ? array_values(array_filter(array_map('trim', explode(',', $request->expertise)))) : [])),
            'department' => $request->department,
            'profgroup' => $request->profgroup,
            'catg' => $request->catg,
            'station' => $request->station,
            'groupname' => $request->groupname,
            'tin' => $request->tin,
            'Licno' => $request->licno,
            'licnoexpiry' => $request->licnoexpiry,
            'phicno' => $request->phicno,
            'phicexpiry' => $request->phicexpiry,
            'phicname' => $request->phicname,
            'phicenable' => $request->boolean('phicenable'),
            'phicrate' => $request->phicrate,
            'S2no' => $request->s2no,
            'PTR' => $request->ptr,
            'clinicroom' => $request->clinicroom,
            'clinichours' => $request->clinichours,
            'pfrate' => $request->pfrate,
            'rodrate' => $request->rodrate,
            'coacode' => $request->coacode,
            'accountno' => $request->accountno,
            'tax' => $request->tax,
            'vatable' => $request->boolean('vatable'),
            'vatrate' => $request->vatrate,
            'VAT' => $request->vatrate ?? $request->VAT,
            'autoAddVAT' => $request->boolean('autoAddVAT'),
            'issuehospOR' => $request->boolean('issuehospOR'),
            'quevisible' => $request->boolean('quevisible', true),
            'allowtextresult' => $request->boolean('allowtextresult'),
            'allowdocsystem' => $request->boolean('allowdocsystem'),
            'disabletext' => $request->boolean('disabletext'),
            'status' => ($request->status == 'ACTIVE' || $request->status == '1' ? true : false),
            'statusreason' => $request->statusreason,
            'otherinfo' => $request->otherinfo,
            'biodata' => $request->biodata
        ]);

        $facilityClientCode = config('app.client_code', env('CLIENT_CODE', '122377'));

        // Detailed Comment: Create credentials in 'doctorsrights' table with username defaulting to last name
        DoctorModel::create([
            'dw_clientcode' => $facilityClientCode,
            'docrefno' => $docrefno,
            'docfname' => $request->docfname,
            'docmname' => $request->docmname,
            'doclname' => $request->doclname,
            'suffix' => $request->suffix,
            'titlename' => $request->titlename,
            'username' => $username,
            'pass' => $request->pass, // Model casts 'pass' => 'hashed'
            'eadd' => $request->emailadd,
            'mnumber' => $request->cellno,
            'tin' => $request->tin,
            'address' => $request->adrs,
            'slcode' => $request->coacode ?? 'SLCODE',
            'taxpercent' => $request->tax,
            'bankacct' => $request->accountno ?? 'BANKACCT',
            'status' => $request->status,
            'expertise' => $request->expertise,
            'proftype' => $request->proftype,
            'doctype' => 0,
            'docmgmt' => 0,
            'consultationfee' => $request->pfrate ?? 0
        ]);

        $profile->where('docrefno', $docrefno)->update(['doccode' => 'PFMD' . str_pad($profile->id, 3, '0', STR_PAD_LEFT)]);

        // Detailed Comment: Structured logging for doctor account registration
        Log::info('Doctor account registered by admin', [
            'docrefno' => $docrefno,
            'username' => $username,
            'docname' => $request->docfname . ' ' . $request->doclname,
            'clientcode' => $facilityClientCode
        ]);

        return response()->json(['success' => true]);
    }

    public function editDoctor(Request $request)
    {
        $username = $request->filled('eusername') ? strtolower(trim($request->eusername)) : strtolower(trim($request->edoclname));

        // Detailed Comment: Update all columns on doctors table
        $doctorprofile = DoctorsProfileModel::where('docrefno', $request->docrefno)
            ->update([
                'docfname' => $request->edocfname,
                'docmname' => $request->edocmname,
                'doclname' => $request->edoclname,
                'suffix' => $request->esuffix,
                'titlename' => $request->etitlename,
                'docfirst' => $request->edocfirst ?? $request->edocfname,
                'docname' => trim($request->edocfname . ' ' . ($request->edocmname ?? '') . ' ' . $request->edoclname . ' ' . ($request->esuffix ?? '')),
                'emailadd' => $request->eemailadd,
                'cellno' => $request->ecellno,
                'adrs' => $request->eadrs,
                'proftype' => $request->eproftype,
                'expertise' => (!empty($request->eexpertise) ? $request->eexpertise : (is_array($request->input('specializations')) ? implode(', ', $request->input('specializations')) : $request->eexpertise)),
                'specializations' => is_array($request->input('specializations')) ? array_values(array_filter($request->input('specializations'))) : ($request->filled('specializations') ? array_values(array_filter(explode(',', $request->input('specializations')))) : ($request->filled('eexpertise') ? array_values(array_filter(array_map('trim', explode(',', $request->eexpertise)))) : [])),
                'department' => $request->edepartment,
                'profgroup' => $request->eprofgroup,
                'catg' => $request->ecatg,
                'station' => $request->estation,
                'groupname' => $request->egroupname,
                'tin' => $request->etin,
                'Licno' => $request->elicno,
                'licnoexpiry' => $request->elicnoexpiry,
                'phicno' => $request->ephicno,
                'phicexpiry' => $request->ephicexpiry,
                'phicname' => $request->ephicname,
                'phicenable' => $request->boolean('ephicenable'),
                'phicrate' => $request->ephicrate,
                'S2no' => $request->es2no,
                'PTR' => $request->eptr,
                'clinicroom' => $request->eclinicroom,
                'clinichours' => $request->eclinichours,
                'pfrate' => $request->epfrate,
                'rodrate' => $request->erodrate,
                'coacode' => $request->ecoacode,
                'accountno' => $request->eaccountno,
                'tax' => $request->etax,
                'vatable' => $request->boolean('evatable'),
                'vatrate' => $request->evatrate,
                'VAT' => $request->evatrate ?? $request->eVAT,
                'autoAddVAT' => $request->boolean('eautoAddVAT'),
                'issuehospOR' => $request->boolean('eissuehospOR'),
                'quevisible' => $request->boolean('equevisible'),
                'allowtextresult' => $request->boolean('eallowtextresult'),
                'allowdocsystem' => $request->boolean('eallowdocsystem'),
                'disabletext' => $request->boolean('edisabletext'),
                'status' => ($request->estatus == 'ACTIVE' || $request->estatus == '1' ? true : false),
                'statusreason' => $request->estatusreason,
                'otherinfo' => $request->eotherinfo,
                'biodata' => $request->ebiodata
            ]);

        $existingPass = DoctorModel::where('docrefno', $request->edocrefno ?? $request->docrefno)->value('pass');
        $doctor = DoctorModel::where(['docrefno' => $request->docrefno])
            ->update([
                'docfname' => $request->edocfname,
                'docmname' => $request->edocmname,
                'doclname' => $request->edoclname,
                'suffix' => $request->esuffix,
                'titlename' => $request->etitlename,
                'username' => $username,
                'pass' => $request->filled('epass') ? bcrypt($request->epass) : $existingPass,
                'eadd' => $request->eemailadd,
                'mnumber' => $request->ecellno,
                'tin' => $request->etin,
                'address' => $request->eadrs,
                'slcode' => $request->ecoacode ?? 'SLCODE',
                'taxpercent' => $request->etax,
                'bankacct' => $request->eaccountno ?? 'BANKACCT',
                'status' => $request->estatus,
                'expertise' => $request->eexpertise,
                'proftype' => $request->eproftype,
                'doctype' => 0,
                'docmgmt' => 0,
                'consultationfee' => $request->epfrate ?? 0
            ]);

        Log::info('Doctor profile updated by admin', ['docrefno' => $request->docrefno, 'username' => $username]);

        if ($doctor !== false && $doctorprofile !== false) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteDoctor(Request $request)
    {
        $request->validate([
            'docrefno' => 'required|string'
        ]);

        DoctorsProfileModel::where('docrefno', $request->docrefno)->delete();
        DoctorModel::where('docrefno', $request->docrefno)->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Fetch secretaries and administrators for the unified Secretaries/Admin Users management table.
     * Supports server-side filtering via 'account_type' or 'type' parameter ('Secretary', 'Admin', or '' / 'all').
     * Maps both account types with consistent attributes while preserving backward-compatible field names.
     */
    public function fetchSecretaries(Request $request)
    {
        $accountType = trim((string) $request->input('account_type', $request->input('type', '')));

        // Detailed Comment: Also check column filter on column 1 (Account Type)
        $colAccountType = trim((string) $request->input('columns.1.search.value', ''));
        if (!empty($colAccountType)) {
            // Unpack regex picklist like ^(Secretary|Admin)$ or direct string
            if (stripos($colAccountType, 'Secretary') !== false && stripos($colAccountType, 'Admin') === false) {
                $accountType = 'Secretary';
            } elseif (stripos($colAccountType, 'Admin') !== false && stripos($colAccountType, 'Secretary') === false) {
                $accountType = 'Admin';
            }
        }

        $secretaries = collect();
        $admins = collect();

        // Detailed Comment: Query secretaries if account_type is empty, 'all', or explicitly 'Secretary'
        if (empty($accountType) || strcasecmp($accountType, 'all') === 0 || strcasecmp($accountType, 'secretary') === 0) {
            $secretaries = SecretaryModel::all()->map(function ($sec) {
                return [
                    'id' => $sec->id,
                    'refno' => $sec->secrefno,
                    'secrefno' => $sec->secrefno,
                    'idno' => $sec->secidno,
                    'account_type' => 'Secretary',
                    'username' => $sec->username ?: strtolower($sec->seclname),
                    'secfname' => $sec->secfname,
                    'secmname' => $sec->secmname,
                    'seclname' => $sec->seclname,
                    'secsuffix' => $sec->secsuffix,
                    'fullname' => trim(($sec->seclname ?? '') . ', ' . ($sec->secfname ?? '') . ' ' . ($sec->secmname ?? '') . ' ' . ($sec->secsuffix ?? '')),
                    'secgender' => $sec->secgender,
                    'secbday' => $sec->secbday,
                    'seccontactno' => $sec->seccontactno,
                    'secemail' => $sec->secemail,
                    'secadrs' => $sec->secadrs,
                    'clientcode' => $sec->clientcode,
                    'verified' => $sec->verified,
                    'source_table' => 'secretaryrights',
                ];
            });
        }

        // Detailed Comment: Query administrators if account_type is empty, 'all', or explicitly 'Admin'
        if (empty($accountType) || strcasecmp($accountType, 'all') === 0 || strcasecmp($accountType, 'admin') === 0) {
            $admins = AdminModel::all()->map(function ($admin) {
                $fname = $admin->adminfname ?: 'Admin';
                $lname = $admin->adminlname ?: ($admin->username ?: 'User');
                $refno = $admin->adminrefno ?: (string)$admin->id;
                return [
                    'id' => $admin->id,
                    'refno' => $refno,
                    'secrefno' => $refno, // Fallback for table action button triggers
                    'adminrefno' => $refno,
                    'idno' => $admin->adminidno ?: ('ADM-' . $admin->id),
                    'account_type' => 'Admin',
                    'username' => $admin->username ?: strtolower($lname),
                    'secfname' => $fname,
                    'secmname' => $admin->adminmname ?: '',
                    'seclname' => $lname,
                    'secsuffix' => '',
                    'fullname' => trim($lname . ', ' . $fname . ' ' . ($admin->adminmname ?? '')),
                    'secgender' => 'N/A',
                    'secbday' => null,
                    'seccontactno' => $admin->admincontactno ?: '',
                    'secemail' => $admin->adminemail ?: $admin->useremail,
                    'secadrs' => '',
                    'clientcode' => $admin->clientcode,
                    'verified' => true,
                    'source_table' => 'adminrights',
                ];
            });
        }

        $allUsers = $secretaries->concat($admins)->values();
        $recordsTotal = $allUsers->count();

        // Detailed Comment: If client requested DataTables server-side pagination, apply search, filters, ordering and slice
        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 10);
            $globalSearch = trim((string) $request->input('search.value', ''));

            // Column-specific searches
            $colSourceTable = trim((string) $request->input('columns.2.search.value', ''));
            $colUsername = trim((string) $request->input('columns.3.search.value', ''));
            $colFullname = trim((string) $request->input('columns.4.search.value', ''));
            $colContact = trim((string) $request->input('columns.5.search.value', ''));
            $colEmail = trim((string) $request->input('columns.6.search.value', ''));

            $filtered = $allUsers->filter(function ($item) use ($globalSearch, $colSourceTable, $colUsername, $colFullname, $colContact, $colEmail) {
                // Global search
                if (!empty($globalSearch)) {
                    $haystack = strtolower($item['username'] . ' ' . $item['fullname'] . ' ' . $item['seccontactno'] . ' ' . $item['secemail'] . ' ' . $item['source_table'] . ' ' . $item['account_type']);
                    if (strpos($haystack, strtolower($globalSearch)) === false) {
                        return false;
                    }
                }
                // Source table column filter
                if (!empty($colSourceTable)) {
                    $cleaned = trim($colSourceTable, '^$()');
                    $targets = explode('|', $cleaned);
                    if (!in_array($item['source_table'], $targets) && stripos($item['source_table'], $colSourceTable) === false) {
                        return false;
                    }
                }
                // Username column filter
                if (!empty($colUsername) && stripos($item['username'], $colUsername) === false) {
                    return false;
                }
                // Fullname column filter
                if (!empty($colFullname) && stripos($item['fullname'], $colFullname) === false) {
                    return false;
                }
                // Contact column filter
                if (!empty($colContact) && stripos($item['seccontactno'], $colContact) === false) {
                    return false;
                }
                // Email column filter
                if (!empty($colEmail) && stripos($item['secemail'], $colEmail) === false) {
                    return false;
                }
                return true;
            })->values();

            $recordsFiltered = $filtered->count();

            // Ordering
            $orderCol = (int) $request->input('order.0.column', 3);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $sortKeyMap = [
                1 => 'account_type',
                2 => 'source_table',
                3 => 'username',
                4 => 'fullname',
                5 => 'seccontactno',
                6 => 'secemail',
            ];
            $sortField = $sortKeyMap[$orderCol] ?? 'username';

            if ($orderDir === 'desc') {
                $filtered = $filtered->sortByDesc($sortField, SORT_NATURAL | SORT_FLAG_CASE)->values();
            } else {
                $filtered = $filtered->sortBy($sortField, SORT_NATURAL | SORT_FLAG_CASE)->values();
            }

            // Pagination slice
            $paged = $length > 0 ? $filtered->slice($start, $length)->values() : $filtered;

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $paged,
                'secretaries' => $paged,
                'success' => true
            ]);
        }

        // Detailed Comment: Fallback for non-DataTables callers / unit tests
        return response()->json([
            'success' => true,
            'secretaries' => $allUsers,
            'data' => $allUsers
        ]);
    }

    /**
     * Fetch secretary details for modal editing.
     */
    public function fetchSecretaryDetails(Request $request)
    {
        $request->validate([
            'secrefno' => 'required|string'
        ]);

        $secretary = SecretaryModel::where('secrefno', $request->secrefno)->first();
        if (!$secretary) {
            return response()->json(['success' => false, 'message' => 'Secretary not found'], 404);
        }

        return response()->json([
            'success' => true,
            'secretary' => $secretary
        ]);
    }

    /**
     * Register a new secretary account with custom or default username (defaults to lowercase lastname).
     */
    public function addSecretary(Request $request)
    {
        $request->validate([
            'secfname' => 'required|string|max:255',
            'secmname' => 'nullable|string|max:255',
            'seclname' => 'required|string|max:255',
            'secsuffix' => 'nullable|string|max:10',
            'username' => 'nullable|string|max:50|unique:secretaryrights,username',
            'secgender' => 'required|in:male,female,MALE,FEMALE',
            'secpassword' => 'required|string|min:5',
            'secbday' => 'nullable|date',
            'seccontactno' => 'nullable|string|max:11',
            'secemail' => 'nullable|email|unique:secretaryrights,secemail',
            'secadrs' => 'nullable|string|max:255'
        ]);

        $secidno = Date::now()->format('Y') . "-" . (SecretaryModel::count() + 1);
        $facilityClientCode = config('app.client_code', env('CLIENT_CODE', '122377'));
        $username = $request->filled('username') ? strtolower(trim($request->username)) : strtolower(trim($request->seclname));

        $secretary = SecretaryModel::create([
            'secrefno' => Date::now()->format('mdYHis') . 'TASK',
            'secidno' => $secidno,
            'username' => $username,
            'secfname' => $request->secfname,
            'secmname' => $request->secmname,
            'seclname' => $request->seclname,
            'secsuffix' => $request->secsuffix,
            'secgender' => strtoupper($request->secgender),
            'secpassword' => $request->secpassword, // Model casts 'secpassword' => 'hashed'
            'secbday' => $request->secbday,
            'seccontactno' => $request->seccontactno,
            'secemail' => $request->secemail,
            'secadrs' => $request->secadrs,
            'clientcode' => $facilityClientCode,
            'recordeddate' => now(),
            'verified' => true
        ]);

        // Detailed Comment: Structured logging for secretary account registration
        Log::info('Secretary account registered by admin', [
            'username' => $username,
            'secidno' => $secidno,
            'secname' => $request->secfname . ' ' . $request->seclname,
            'clientcode' => $facilityClientCode
        ]);

        return response()->json(['success' => true, 'secretary' => $secretary]);
    }

    /**
     * Update secretary profile or credentials.
     * Supports both admin updating any secretary by secrefno and secretary self-service update.
     */
    public function editSecretary(Request $request)
    {
        $secrefno = $request->input('secrefno');

        // Admin-driven update by secrefno
        if ($secrefno) {
            $secretary = SecretaryModel::where('secrefno', $secrefno)->first();
            if (!$secretary) {
                return response()->json(['success' => false, 'message' => 'Secretary not found'], 404);
            }

            $updateData = [];
            if ($request->has('secfname')) $updateData['secfname'] = $request->secfname;
            if ($request->has('secmname')) $updateData['secmname'] = $request->secmname;
            if ($request->has('seclname')) $updateData['seclname'] = $request->seclname;
            if ($request->has('secsuffix')) $updateData['secsuffix'] = $request->secsuffix;
            if ($request->has('secgender')) $updateData['secgender'] = strtoupper($request->secgender);
            if ($request->has('secbday')) $updateData['secbday'] = $request->secbday;
            if ($request->has('seccontactno')) $updateData['seccontactno'] = $request->seccontactno;
            if ($request->has('secemail')) $updateData['secemail'] = $request->secemail;
            if ($request->has('secadrs')) $updateData['secadrs'] = $request->secadrs;

            if ($request->filled('username')) {
                $updateData['username'] = strtolower(trim($request->username));
            } elseif ($request->has('seclname') && empty($secretary->username)) {
                $updateData['username'] = strtolower(trim($request->seclname));
            }

            if ($request->filled('secpassword')) {
                $updateData['secpassword'] = $request->secpassword;
            }

            $secretary->update($updateData);

            Log::info('Secretary profile updated by admin', [
                'secrefno' => $secrefno,
                'username' => $secretary->username
            ]);

            return response()->json(['success' => true]);
        }

        // Secretary self-service profile update
        if (auth()->guard('secretary')->check()) {
            $user = SecretaryModel::where('secrefno', auth()->guard('secretary')->user()->secrefno)
                ->update([
                    'seccontactno' => $request->sec_contact ?? $request->seccontactno,
                    'secemail' => $request->sec_email ?? $request->secemail
                ]);

            return response()->json(['success' => (bool)$user]);
        }

        return response()->json(['success' => false, 'message' => 'Unauthorized or missing secrefno'], 400);
    }

    /**
     * Delete a secretary account.
     */
    public function deleteSecretary(Request $request)
    {
        $request->validate([
            'secrefno' => 'required|string|exists:secretaryrights,secrefno'
        ]);

        $secretary = SecretaryModel::where('secrefno', $request->secrefno)->first();
        if ($secretary) {
            SecretaryDoctorsModel::where('secrefno', $request->secrefno)->delete();
            $secretary->delete();

            Log::info('Secretary account deleted by admin', ['secrefno' => $request->secrefno]);

            return response()->json([
                'success' => true
            ]);
        }

        return response()->json(['success' => false, 'message' => 'Secretary not found'], 404);
    }

    /**
     * Fetch all administrators for admin users management.
     */
    public function fetchAdmins()
    {
        $admins = AdminModel::all();
        return response()->json(['admins' => $admins]);
    }

    /**
     * Fetch details of a specific admin user for modal editing.
     */
    public function fetchAdminDetails(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'adminrefno' => 'nullable|string'
        ]);

        $query = AdminModel::query();
        if ($request->filled('adminrefno')) {
            $query->where('adminrefno', $request->adminrefno);
        } elseif ($request->filled('id')) {
            $query->where('id', $request->id);
        } else {
            return response()->json(['success' => false, 'message' => 'Admin identifier required'], 400);
        }

        $admin = $query->first();
        if (!$admin) {
            return response()->json(['success' => false, 'message' => 'Admin not found'], 404);
        }

        return response()->json(['success' => true, 'admin' => $admin]);
    }

    /**
     * Register a new admin user with username defaulting to lowercase lastname.
     */
    public function addAdmin(Request $request)
    {
        $request->validate([
            'adminfname' => 'required|string|max:255',
            'adminmname' => 'nullable|string|max:255',
            'adminlname' => 'required|string|max:255',
            'username' => 'nullable|string|max:50|unique:adminrights,username',
            'password' => 'required|string|min:5',
            'admincontactno' => 'nullable|string|max:20',
            'adminemail' => 'nullable|email|max:255|unique:adminrights,adminemail'
        ]);

        $username = $request->filled('username') ? strtolower(trim($request->username)) : strtolower(trim($request->adminlname));
        $facilityClientCode = config('app.client_code', env('CLIENT_CODE', '122377'));
        $adminrefno = Date::now()->format('mdYHis') . 'ADM';
        $adminidno = Date::now()->format('Y') . "-ADM-" . (AdminModel::count() + 1);

        $admin = AdminModel::create([
            'adminrefno' => $adminrefno,
            'adminidno' => $adminidno,
            'username' => $username,
            'password' => Hash::make($request->password),
            'adminfname' => $request->adminfname,
            'adminmname' => $request->adminmname,
            'adminlname' => $request->adminlname,
            'admincontactno' => $request->admincontactno,
            'adminemail' => $request->adminemail,
            'useremail' => $request->adminemail,
            'clientcode' => $facilityClientCode,
            'active' => true
        ]);

        Log::info('Admin account registered by admin', [
            'username' => $username,
            'adminrefno' => $adminrefno,
            'clientcode' => $facilityClientCode
        ]);

        return response()->json(['success' => true, 'admin' => $admin]);
    }

    /**
     * Update an admin user profile and credentials.
     */
    public function editAdmin(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'adminrefno' => 'nullable|string',
            'adminfname' => 'required|string|max:255',
            'adminmname' => 'nullable|string|max:255',
            'adminlname' => 'required|string|max:255',
            'password' => 'nullable|string|min:5',
            'admincontactno' => 'nullable|string|max:20',
            'adminemail' => 'nullable|email|max:255'
        ]);

        $query = AdminModel::query();
        if ($request->filled('adminrefno')) {
            $query->where('adminrefno', $request->adminrefno);
        } elseif ($request->filled('id')) {
            $query->where('id', $request->id);
        } else {
            return response()->json(['success' => false, 'message' => 'Admin identifier required'], 400);
        }

        $admin = $query->first();
        if (!$admin) {
            return response()->json(['success' => false, 'message' => 'Admin not found'], 404);
        }

        $username = $request->filled('username') ? strtolower(trim($request->username)) : ($admin->username ?: strtolower(trim($request->adminlname)));

        $admin->adminfname = $request->adminfname;
        $admin->adminmname = $request->adminmname;
        $admin->adminlname = $request->adminlname;
        $admin->admincontactno = $request->admincontactno;
        $admin->adminemail = $request->adminemail;
        $admin->useremail = $request->adminemail;
        $admin->username = $username;

        if ($request->filled('password')) {
            $admin->password = Hash::make($request->password);
        }

        $admin->save();

        Log::info('Admin account updated by admin', [
            'admin_id' => $admin->id,
            'username' => $username
        ]);

        return response()->json(['success' => true, 'admin' => $admin]);
    }

    /**
     * Delete an admin user account (guards against deleting self).
     */
    public function deleteAdmin(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'adminrefno' => 'nullable|string'
        ]);

        $query = AdminModel::query();
        if ($request->filled('adminrefno')) {
            $query->where('adminrefno', $request->adminrefno);
        } elseif ($request->filled('id')) {
            $query->where('id', $request->id);
        } else {
            return response()->json(['success' => false, 'message' => 'Admin identifier required'], 400);
        }

        $admin = $query->first();
        if (!$admin) {
            return response()->json(['success' => false, 'message' => 'Admin not found'], 404);
        }

        // Prevent admin from deleting their own active logged in account
        if (auth()->guard('admin')->check() && auth()->guard('admin')->id() == $admin->id) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own active administrator account.'], 403);
        }

        $admin->delete();

        Log::info('Admin account deleted by admin', ['deleted_admin_id' => $admin->id]);

        return response()->json(['success' => true]);
    }

    /**
     * Detailed Comment: Self-service profile update for authenticated administrator.
     * Allows admin to edit their own profile and credentials in 'adminrights',
     * strictly bound to the authenticated admin's ID to prevent cross-user tampering.
     */
    public function updateAdminProfile(Request $request)
    {
        $adminAuth = auth()->guard('admin')->user();
        if (!$adminAuth) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated admin'], 401);
        }

        $request->validate([
            'adminfname' => 'required|string|max:100',
            'adminlname' => 'required|string|max:100',
            'username' => 'required|string|max:50|unique:adminrights,username,' . $adminAuth->id,
            'password' => 'nullable|string|min:5',
            'admincontactno' => 'nullable|string|max:25',
            'adminemail' => 'nullable|email|max:100'
        ]);

        $updateData = [
            'adminfname' => $request->adminfname,
            'adminmname' => $request->adminmname,
            'adminlname' => $request->adminlname,
            'admincontactno' => $request->admincontactno,
            'adminemail' => $request->adminemail,
            'username' => strtolower(trim($request->username))
        ];

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        AdminModel::where('id', $adminAuth->id)->update($updateData);

        Log::info('Admin self-service profile updated', [
            'admin_id' => $adminAuth->id,
            'username' => $request->username
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.'
        ]);
    }

    /**
     * Fetch assigned and available doctors for a secretary.
     * Excludes NULL docrefno values to prevent MySQL NOT IN evaluating to empty set.
     */
    public function fetchSecretaryDoctors(Request $request)
    {
        $request->validate([
            'secrefno' => 'required|string'
        ]);

        $secretary = SecretaryModel::where('secrefno', $request->secrefno)->first();
        if (!$secretary) {
            return response()->json(['success' => false, 'message' => 'Secretary not found'], 404);
        }

        // Ensure we only fetch assigned doctors with valid docrefno
        $assigned = SecretaryDoctorsModel::where('secrefno', $request->secrefno)
            ->whereNotNull('docrefno')
            ->get();

        $assigned_doctors = [];
        foreach ($assigned as $assign) {
            $doc = DoctorsProfileModel::select(['docrefno', 'docname'])
                ->where('docrefno', $assign->docrefno)
                ->first();
            if ($doc) {
                $assigned_doctors[] = $doc;
            }
        }

        $availableDocRefNos = SecretaryDoctorsModel::where('secrefno', $request->secrefno)
            ->whereNotNull('docrefno')
            ->pluck('docrefno')
            ->filter()
            ->all();

        // Available doctors query filtering out assigned ones and NULL docrefno
        $availQuery = DoctorsProfileModel::select(['docrefno', 'docname'])
            ->whereNotNull('docrefno');

        if (!empty($availableDocRefNos)) {
            $availQuery->whereNotIn('docrefno', $availableDocRefNos);
        }

        $avail_doctors = $availQuery->get();

        return response()->json([
            'success' => true,
            'name' => trim(($secretary->seclname . ', ' . $secretary->secfname . ' ' . $secretary->secmname . ' ' . $secretary->secsuffix)),
            'assigned_doctors' => $assigned_doctors,
            'avail_doctors' => $avail_doctors
        ]);
    }

    /**
     * Synchronize assigned doctors for a secretary.
     * Adds newly assigned doctors and removes unassigned doctors.
     */
    public function saveAppendedDoctors(Request $request)
    {
        $request->validate([
            'secrefno' => 'required|string',
            'doctors' => 'nullable|array'
        ]);

        $secrefno = $request->secrefno;
        $submittedDoctors = array_values(array_filter($request->input('doctors', []) ?: []));

        // Synchronize: Delete doctors no longer in the assigned list
        SecretaryDoctorsModel::where('secrefno', $secrefno)
            ->whereNotIn('docrefno', $submittedDoctors)
            ->delete();

        // Ensure submitted doctors are created if they do not already exist
        foreach ($submittedDoctors as $doctor) {
            if (!empty($doctor)) {
                SecretaryDoctorsModel::firstOrCreate(
                    ['docrefno' => $doctor, 'secrefno' => $secrefno],
                    [
                        'recordedby' => auth()->user() ? (auth()->user()->username ?? 'admin') : 'admin',
                        'recordeddate' => Date::now(),
                        'active' => true
                    ]
                );
            }
        }

        Log::info('Secretary doctors synchronized', [
            'secrefno' => $secrefno,
            'assigned_count' => count($submittedDoctors)
        ]);

        return response()->json(['success' => true, 'doctors' => $submittedDoctors]);
    }

    public function fetchProfile(Request $request)
    {
        $profile = KayakapProfileModel::firstOrFail();

        return response()->json(['profile' => $profile]);
    }

    public function fetchAddressData(Request $request)
    {
        $region = DB::table('lib_region')
            ->select(['REGION_CODE', 'REGION_DESC', 'PRO_CODE'])
            ->get();

        $province = DB::table('lib_province')
            ->select(['PROCODE', 'PROV_NAME', 'PROVINCE'])
            ->orderBy('PROV_NAME', 'ASC')
            ->get();

        $municipalities = DB::table('lib_municipality')
            ->select(['PROCODE', 'PROVINCE', 'MUNICIPALITY', 'MUN_NAME'])
            ->orderBy('MUN_NAME', 'ASC')
            ->get();

        $barangay = DB::table('lugar_barangay')
            ->select(['MUNICIPALITY', 'PROVINCE', 'BARANGAY', 'BRGY_NAME'])
            ->orderBy("BRGY_NAME", 'ASC')
            ->get();

        $data['regions'] = $region;
        $data['provinces'] = $province;
        $data['municipalities'] = $municipalities;
        $data['barangay'] = $barangay;

        return response()->json(['data' => $data]);
    }

    public function loadCompanyProfile()
    {
        // Detailed Comment: Load company profile including PSGC address codes from kayakapmd_profile
        $profile = KayakapProfileModel::select([
            'HOSP_NAME',
            'HOSP_ADDREG',
            'HOSP_ADDPROV',
            'HOSP_ADDMUN',
            'HOSP_ADDBRGY',
            'HOSP_ADDZIPCODE',
            'EMAIL_ADD',
            'TEL_NO'
        ])->first();

        $company = PCBModel::select([
            'userid',
            'passwd',
            'hciaccreno'
        ])
            ->first();

        if ($profile) {
            return response()->json([
                'success' => true,
                'profile' => $profile,
                'company' => $company ?: (object) [
                    'userid' => '',
                    'passwd' => '',
                    'hciaccreno' => ''
                ]
            ]);
        }

        return response()->json(['success' => false]);
    }

    public function updateProfile(Request $request)
    {
        // Detailed Comment: Update company profile including PSGC address attributes from admin profile form
        $data = [
            'HOSP_NAME' => $request->comp_name,
            'TEL_NO' => $request->comp_tel,
            'EMAIL_ADD' => $request->comp_email,
            'HOSP_ADDREG' => $request->phregion,
            'HOSP_ADDPROV' => $request->phprov,
            'HOSP_ADDMUN' => $request->phmun,
            'HOSP_ADDBRGY' => $request->phbrgy,
            'HOSP_ADDZIPCODE' => $request->phzipcode,
        ];

        if ($request->hasFile('comp_logo')) {
            $file = $request->file('comp_logo');

            $request->validate([
                'comp_logo' => 'image|mimes:jpg,jpeg,png|max:2048'
            ]);

            $file->move(public_path('images'), 'company_logo.png');
        }

        $profile = KayakapProfileModel::first();
        if (!$profile) {
            return response()->json(['success' => false]);
        }

        KayakapProfileModel::query()->update($data);

        return response()->json(['success' => true]);
    }

    public function savePhilHealthCreds(Request $request)
    {
        $profile = KayakapProfileModel::first()->update([
            'hci_username' => $request->ph_username,
            'hci_password' => $request->ph_password,
            'hci_accre_no' => $request->ph_accreno
        ]);

        $pcb = PCBModel::first();
        $pcb->update([
            'userid' => $request->ph_username,
            'passwd' => $request->ph_password,
            'hciaccreno' => $request->ph_accreno
        ]);

        if ($profile && $pcb) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    /**
     * Detailed Comment: Fetches current active theme settings for the facility.
     */
    public function fetchThemeSettings(Request $request)
    {
        $profile = KayakapProfileModel::first();
        $theme = ThemeModel::getActiveTheme($profile?->clientcode);

        return response()->json([
            'success' => true,
            'theme' => $theme,
        ]);
    }

    /**
     * Detailed Comment: Updates facility visual theme settings (colors, buttons, header/footer,
     * text colors, and optional custom logo file upload), persisting to the 'theme' table
     * linked to the facility profile via clientcode.
     */
    public function updateThemeSettings(Request $request)
    {
        $hexRegex = 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/';

        $request->validate([
            'theme_name'           => 'nullable|string|max:100',
            'app_background'       => ['required', $hexRegex],
            'header_bg'            => ['required', $hexRegex],
            'header_text_color'    => ['required', $hexRegex],
            'header_accent_color'  => ['required', $hexRegex],
            'footer_bg'            => ['required', $hexRegex],
            'footer_text_color'    => ['required', $hexRegex],
            'sidebar_bg'           => ['required', $hexRegex],
            'sidebar_text_color'   => ['required', $hexRegex],
            'primary_button_bg'    => ['required', $hexRegex],
            'primary_button_text'  => ['required', $hexRegex],
            'secondary_button_bg'  => ['required', $hexRegex],
            'secondary_button_text'=> ['required', $hexRegex],
            'text_color'           => ['required', $hexRegex],
            'logo_file'            => 'nullable|image|mimes:jpg,jpeg,png,svg,webp|max:2048',
        ]);

        $profile = KayakapProfileModel::first();
        $clientcode = $profile?->clientcode;

        $theme = ThemeModel::where('clientcode', $clientcode)->first()
            ?? ThemeModel::first()
            ?? new ThemeModel(['clientcode' => $clientcode]);

        $theme->clientcode           = $clientcode;
        $theme->theme_name           = $request->theme_name ?: ($theme->theme_name ?: 'Custom Theme');
        $theme->app_background       = $request->app_background;
        $theme->header_bg            = $request->header_bg;
        $theme->header_text_color    = $request->header_text_color;
        $theme->header_accent_color  = $request->header_accent_color;
        $theme->footer_bg            = $request->footer_bg;
        $theme->footer_text_color    = $request->footer_text_color;
        $theme->sidebar_bg           = $request->sidebar_bg;
        $theme->sidebar_text_color   = $request->sidebar_text_color;
        $theme->primary_button_bg    = $request->primary_button_bg;
        $theme->primary_button_text  = $request->primary_button_text;
        $theme->secondary_button_bg  = $request->secondary_button_bg;
        $theme->secondary_button_text= $request->secondary_button_text;
        $theme->text_color           = $request->text_color;
        $theme->is_active            = true;

        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $extension = $file->getClientOriginalExtension();
            $filename = 'clinic_custom_logo_' . time() . '.' . $extension;

            // Detailed Comment: Store uploaded theme logo in public/images/theme
            $destinationPath = public_path('images/theme');
            if (!file_exists($destinationPath)) {
                mkdir($destinationPath, 0755, true);
            }

            $file->move($destinationPath, $filename);
            $theme->logo_path = 'images/theme/' . $filename;
        }

        $theme->save();

        Log::info('Theme settings successfully updated by admin', [
            'clientcode' => $clientcode,
            'theme_id'   => $theme->id,
            'theme_name' => $theme->theme_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Theme updated successfully.',
            'theme'   => $theme,
        ]);
    }

    /**
     * Detailed Comment: Resets facility visual theme to factory default branding colors.
     */
    public function resetThemeSettings(Request $request)
    {
        $profile = KayakapProfileModel::first();
        $clientcode = $profile?->clientcode;

        $theme = ThemeModel::where('clientcode', $clientcode)->first()
            ?? ThemeModel::first()
            ?? new ThemeModel(['clientcode' => $clientcode]);

        $theme->clientcode           = $clientcode;
        $theme->theme_name           = 'Default Theme';
        $theme->app_background       = '#f8f9fa';
        $theme->header_bg            = '#f4c79f';
        $theme->header_text_color    = '#212529';
        $theme->header_accent_color  = '#ffa500';
        $theme->footer_bg            = '#f8f9fa';
        $theme->footer_text_color    = '#6c757d';
        $theme->sidebar_bg           = '#e9ecef';
        $theme->sidebar_text_color   = '#212529';
        $theme->primary_button_bg    = '#0d6efd';
        $theme->primary_button_text  = '#ffffff';
        $theme->secondary_button_bg  = '#6c757d';
        $theme->secondary_button_text= '#ffffff';
        $theme->text_color           = '#212529';
        $theme->logo_path            = 'images/logo.png';
        $theme->is_active            = true;
        $theme->save();

        Log::info('Theme settings reset to factory defaults by admin', [
            'clientcode' => $clientcode,
            'theme_id'   => $theme->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Theme has been reset to defaults.',
            'theme'   => $theme,
        ]);
    }

    // Medicine-related
    public function fetchMedicineMasterlist(Request $request)
    {
        $start = $request->input('start', 0);
        $length = $request->input('length', 25);

        $medicines = MedicineMasterlistModel::orderBy('medicine_name', 'ASC')
            ->offset($start)
            ->limit($length)
            ->get();

        $recordsTotal = $medicines->count();
        $recordsFiltered = $medicines->count();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'medicines' => $medicines
        ]);
    }

    public function fetchMedicineReference(Request $request)
    {
        $start = $request->input('start', 0);
        $length = $request->input('length', 25);
        $search = $request->input('search.value');

        $query = MedicineModel::query();
        $recordsTotal = $query->count();

        if (!empty($search)) {
            $query->where('drug_dscr', 'like', "%{$search}%")
                ->orWhere('drug_code', 'like', "%{$search}%");
        }

        $recordsFiltered = $query->count();

        $medicines = $query->orderBy('drug_dscr')
            ->offset($start)
            ->limit($length)
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $medicines
        ]);
    }

    public function addMedicine(Request $request)
    {
        $record = MedicineMasterlistModel::create([
            'medicine_refno' => 'MED' . Date::now()->format('mdYHis'),
            'medicine_name' => $request->med_name,
            'philhealth_refno' => $request->ph_id
        ]);

        if ($record) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteMedicine(Request $request)
    {
        $record = MedicineMasterlistModel::where([
            'medicine_refno' => $request->medicinerefno
        ])->delete();

        if ($record) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    // Diagnostics-related
    public function fetchDiagnosticCategory(Request $request = null)
    {
        $request = $request ?: request();

        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 15);
            $globalSearch = trim((string) $request->input('search.value', ''));

            $query = DiagnosticsCategoryModel::query();
            $recordsTotal = (clone $query)->count();

            // Detailed Comment: Global search across category_refno and category_name
            if (!empty($globalSearch)) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('category_name', 'like', "%{$globalSearch}%")
                        ->orWhere('category_refno', 'like', "%{$globalSearch}%");
                });
            }

            // Detailed Comment: Column-specific filtering
            $colRef = trim((string) $request->input('columns.1.search.value', ''));
            $colName = trim((string) $request->input('columns.2.search.value', ''));
            if (!empty($colRef)) {
                $query->where('category_refno', 'like', "%{$colRef}%");
            }
            if (!empty($colName)) {
                $query->where('category_name', 'like', "%{$colName}%");
            }

            $recordsFiltered = (clone $query)->count();

            // Detailed Comment: Directional ordering
            $orderCol = (int) $request->input('order.0.column', 2);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $sortField = ($orderCol === 1) ? 'category_refno' : 'category_name';
            $query->orderBy($sortField, $orderDir);

            if ($length > 0) {
                $query->skip($start)->take($length);
            }

            $data = $query->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
                'categories' => $data,
                'success' => true
            ]);
        }

        $categories = DiagnosticsCategoryModel::orderBy('category_name', 'ASC')->get();
        return response()->json([
            'success' => true,
            'categories' => $categories,
            'data' => $categories
        ]);
    }

    public function saveDiagnosticCategory(Request $request)
    {
        // Detailed Comment: Support both category_name and legacy categoryname field names
        $catg = DiagnosticsCategoryModel::create([
            'category_refno' => Date::now()->format('mdYHis') . 'CCATG',
            'category_name' => $request->category_name ?? $request->categoryname
        ]);

        if ($catg) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteDiagnosticCategory(Request $request)
    {
        // Detailed Comment: Support both category_refno and legacy refno field names
        $ref = $request->category_refno ?? $request->refno;
        $catg = DiagnosticsCategoryModel::where(['category_refno' => $ref])->delete();

        if ($catg) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function fetchDiagnostic(Request $request)
    {
        $result = DiagnosticsMasterlistModel::with('category')->get();
        $categories = DiagnosticsCategoryModel::all();

        return response()->json(['results' => $result, 'categories' => $categories]);
    }

    public function createDiagnostic(Request $request)
    {
        $result = DiagnosticsMasterlistModel::create([
            'diagnosticrefno' => Date::now()->format('mdYHis') . 'DIAG',
            'diagnostic_name' => $request->name,
            'diagnostic_catg' => $request->category
        ]);

        if ($result) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function editMedicine(Request $request)
    {
        $record = MedicineMasterlistModel::where(['medicine_refno' => $request->medrefno])
            ->update([
                'medicine_name' => $request->med_name,
                'philhealth_refno' => $request->ph_id
            ]);

        if ($record) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteDiagnostic(Request $request)
    {
        // Detailed Comment: If consultationrefno is provided, delegate to patient consultation diagnostic request deletion
        if ($request->filled('consultationrefno')) {
            return app(DoctorController::class)->deleteDiagnostic($request);
        }

        $result = DiagnosticsMasterlistModel::where([
            'diagnosticrefno' => $request->refno
        ])->delete();

        if ($result) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    // Charges-related
    public function fetchChargeCategories(Request $request = null)
    {
        $request = $request ?: request();

        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 15);
            $globalSearch = trim((string) $request->input('search.value', ''));

            $query = ChargesCategoryModel::query();
            $recordsTotal = (clone $query)->count();

            // Detailed Comment: Global search across categoryrefno and categoryname
            if (!empty($globalSearch)) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('categoryname', 'like', "%{$globalSearch}%")
                        ->orWhere('categoryrefno', 'like', "%{$globalSearch}%");
                });
            }

            // Detailed Comment: Column-specific filtering
            $colRef = trim((string) $request->input('columns.1.search.value', ''));
            $colName = trim((string) $request->input('columns.2.search.value', ''));
            if (!empty($colRef)) {
                $query->where('categoryrefno', 'like', "%{$colRef}%");
            }
            if (!empty($colName)) {
                $query->where('categoryname', 'like', "%{$colName}%");
            }

            $recordsFiltered = (clone $query)->count();

            // Detailed Comment: Directional ordering
            $orderCol = (int) $request->input('order.0.column', 2);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $sortField = ($orderCol === 1) ? 'categoryrefno' : 'categoryname';
            $query->orderBy($sortField, $orderDir);

            if ($length > 0) {
                $query->skip($start)->take($length);
            }

            $data = $query->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
                'categories' => $data,
                'success' => true
            ]);
        }

        $categories = ChargesCategoryModel::orderBy('categoryname', 'ASC')->get();
        return response()->json([
            'success' => true,
            'categories' => $categories,
            'data' => $categories
        ]);
    }

    public function fetchChargeCategoriesSc(Request $request)
    {
        $categories = ChargesCategoryModel::all();
        $pxcharges = DocChargesModel::where(['consultationrefno' => $request->consultationrefno])->get();

        return response()->json(['categories' => $categories, 'pxcharges' => $pxcharges]);
    }

    public function saveChargeCategory(Request $request)
    {
        $category = ChargesCategoryModel::create([
            'categoryrefno' => now()->format('mdYHis') . 'CHGCAT',
            'categoryname' => $request->categoryname
        ]);

        if ($category) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function editChargeCategory(Request $request)
    {
        $category = ChargesCategoryModel::where('categoryrefno', $request->ecategoryrefno)->first();
        if ($category) {
            $category->update([
                'categoryname' => $request->categoryname
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteChargeCategory(Request $request)
    {
        $category = ChargesCategoryModel::where('categoryrefno', $request->categoryrefno)->first();
        if ($category) {
            $category->delete();

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function fetchSpecificCharges(Request $request)
    {
        $charges = ChargesModel::where('chargerefno', $request->chargerefno)->get();

        return response()->json(['charges' => $charges]);
    }

    public function fetchAllCharges(Request $request)
    {
        $appendedCharges = StocksLedgerModel::where(['px_consultcode_cn' => $request->consultationrefno])->pluck('prodcode')->toArray();
        // Detailed Comment: Select all price tiers (regular, phic, hmo, others) so patient charges differ by patient type
        $query = StocksListingModel::query();
        if ($request->category && $request->category !== 'ALL') {
            $query->where('item_grouping', $request->category);
        }
        $charges = $query->select([
            'prod_itemdscr',
            'prodcode',
            'item_grouping',
            'price_regular',
            'price_phic',
            'price_hmo',
            'price_others'
        ])->whereNotIn('prodcode', $appendedCharges)->get();

        // // already-appended charge refnos
        // $appendedCharges = DocChargesModel::where('consultationrefno', $request->consultationrefno)
        //     ->pluck('servicerefno')
        //     ->toArray(); // important

        // $charges = ChargesModel::when($request->category, function ($q) use ($request) {
        //     $q->where('charge_category', $request->category);
        // })
        //     ->whereNotIn('chargerefno', $appendedCharges)
        //     ->get();

        return response()->json(['charges' => $charges]);
    }

    public function fetchChargePrice(Request $request)
    {
        $prices = StocksListingModel::select(['price_regular', 'price_phic', 'price_hmo', 'price_others'])
            ->where(['prodcode' => $request->prodcode])
            ->first();

        return response()->json([
            'prices' => $prices
        ]);
    }

    public function fetchCharges()
    {
        $charges = ChargesModel::join('charges_category', 'charges_masterlist.charge_category', '=', 'charges_category.categoryrefno')
            ->select('charges_masterlist.*', 'charges_category.categoryname')
            ->get();

        return response()->json(['charges' => $charges]);
    }

    public function saveCharge(Request $request)
    {
        $charge = ChargesModel::create([
            'chargerefno' => now()->format('mdYHis') . 'CHRG',
            'charge_name' => $request->charge_name,
            'charge_category' => $request->charge_catg,
            'charge_amount' => $request->charge_amount,
        ]);

        if ($charge) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function editCharge(Request $request)
    {
        $charge = ChargesModel::where('chargerefno', $request->chargerefno)->first();
        if ($charge) {
            $charge->update([
                'charge_name' => $request->charge_name,
                'charge_category' => $request->charge_category,
                'charge_amount' => $request->charge_amount,
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function deleteCharge(Request $request)
    {
        $charge = ChargesModel::where('chargerefno', $request->chargerefno)->first();
        if ($charge) {
            $charge->delete();

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    public function phDiagnosticLib(Request $request)
    {
        $totalCount = PhilHealthDiagModel::count();
        $totalFiltered = $totalCount;

        $limit = $request->input('length');
        $start = $request->input('start');
        $search = $request->input('search.value');

        $query = PhilHealthDiagModel::select(['diagnostic_id', 'diagnostic_desc']);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('diagnostic_id', 'LIKE', "%{$search}%")
                    ->orWhere('diagnostic_desc', 'LIKE', "%{$search}%");
            });

            $totalFiltered = $query->count();
        }

        $data = $query
            ->offset($start)
            ->limit($limit)
            ->orderByRaw('CAST(diagnostic_id AS UNSIGNED) ASC')
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalCount,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ]);
    }

    public function phMedicineLib(Request $request)
    {
        $columns = [
            'm.DRUG_CODE',
            'm.DRUG_DESC',
            'g.gen_desc',
            's.salt_desc',
            'f.form_desc',
            'st.strength_desc',
            'u.unit_desc'
        ];

        $totalCount = PhilHealthMedsModel::from('dw_lib_medicine as m')->count();
        $totalFiltered = $totalCount;

        $limit = $request->input('length');
        $start = $request->input('start');
        $search = $request->input('search.value');

        $query = PhilHealthMedsModel::from('dw_lib_medicine as m')
            ->leftJoin('dw_lib_meds_generic as g', 'm.GEN_CODE', '=', 'g.gen_code')
            ->leftJoin('dw_lib_meds_salt as s', 'm.SALT_CODE', '=', 's.salt_code')
            ->leftJoin('dw_lib_meds_form as f', 'm.FORM_CODE', '=', 'f.form_code')
            ->leftJoin('dw_lib_meds_strength as st', 'm.STRENGTH_CODE', '=', 'st.strength_code')
            ->leftJoin('dw_lib_meds_unit as u', 'm.UNIT_CODE', '=', 'u.unit_code')
            ->select($columns);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('m.DRUG_CODE', 'LIKE', "%{$search}%")
                    ->orWhere('m.DRUG_DESC', 'LIKE', "%{$search}%")
                    ->orWhere('g.gen_desc', 'LIKE', "%{$search}%")
                    ->orWhere('s.salt_desc', 'LIKE', "%{$search}%")
                    ->orWhere('f.form_desc', 'LIKE', "%{$search}%")
                    ->orWhere('st.strength_desc', 'LIKE', "%{$search}%")
                    ->orWhere('u.unit_desc', 'LIKE', "%{$search}%");
            });

            $totalFiltered = $query->count();
        }

        $orderColumnIndex = $request->input('order.0.column');
        $orderDir = $request->input('order.0.dir');

        $orderableColumns = [
            'm.DRUG_CODE',
            'm.DRUG_DESC',
            'g.gen_desc',
            's.salt_desc',
            'f.form_desc',
            'st.strength_desc',
            'u.unit_desc'
        ];

        if (isset($orderColumnIndex)) {
            $query->orderBy($orderableColumns[$orderColumnIndex], $orderDir);
        }

        $data = $query
            ->offset($start)
            ->limit($limit)
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $totalCount,
            'recordsFiltered' => $totalFiltered,
            'data' => $data,
        ]);
    }

    // PhilHealth-related
    public function fetchPatientsConsultation(Request $request)
    {
        $query = ConsultationModel::query();

        $start = $request->input('start', 0);
        $length = $request->input('length', 25);
        $search = $request->input('search.value');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('patientname', 'LIKE', "%{$search}%")
                    ->orWhere('pxmidname', 'LIKE', "%{$search}%")
                    ->orWhere('pxlastname', 'LIKE', "%{$search}%")
                    ->orWhere('pincode', 'LIKE', "%{$search}%")
                    ->orWhere('casecode', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('filter_start') && $request->filled('filter_end')) {
            $query->whereBetween(
                'consultation_date',
                [
                    Carbon::parse($request->filter_start)->startOfDay(),
                    Carbon::parse($request->filter_end)->endOfDay()
                ]
            );
        }

        $recordsFiltered = $query->count();
        $recordsTotal = ConsultationModel::count();

        $records = $query->offset($start)
            ->limit($length)
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records
        ]);
    }

    public function exportToMasterlist(Request $request)
    {
        $consultations = ConsultationModel::all();
        $masterlist = new PatientMasterlist();

        $count = 0;
        foreach ($consultations as $record) {
            if ($masterlist->where(['pincode' => $record->pincode])->first()) {
                continue;
            }

            $masterlist->create([
                'pxrefno' => $record->pxrefno,
                'pincode' => $record->pincode,
                'casecode' => $record->casecode,
                'patientname' => trim($record->patientname . ' ' . $record->pxmidname . ' ' . $record->pxlastname . ' ' . $record->pxsuffix),
                'pxlastname' => $record->pxlastname,
                'pxfirstname' => $record->patientname,
                'pxmidname' => $record->pxmidname,
                'pxsuffix' => $record->pxsuffix,
                'gender' => $record->gender,
                'birthday' => $record->birthday,
                'age' => $record->age,
                'mobilenumber' => $record->mobilenumber,
                'emailaddress' => $record->emailaddress,
                'address' => $record->address,
                'last_consultation' => $record->consultationdate,
                'last_docrefno' => $record->docrefno,
                'status' => $record->status,
                'recordedby' => $record->recordedby,
                'recordeddate' => $record->recordeddate,
                'updatedby' => $record->updatedby,
                'updated' => $record->updated,
            ]);

            $count++;
        }

        return response()->json(['success' => true, 'count' => $count]);
    }


    public function fetchPatientMasterlist(Request $request)
    {
        $start = $request->input('start', 0);
        $length = $request->input('length', 25);
        $search = $request->input('search.value');

        $query = ConsultationModel::query();

        if (!empty($search)) {
            $query->where('patientname', 'like', "%{$search}%");
        }

        $recordsFiltered = $query->count();
        $recordsTotal = ConsultationModel::count();

        $records = $query->select(['pincode', 'patientname', 'consultation_date'])
            ->offset($start)
            ->limit($length)
            ->orderBy('consultation_date', 'desc')
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records
        ]);
    }

    public function fetchSoapData(Request $request)
    {
        $start = $request->input('start', 0);
        $length = $request->input('length', 25);
        $search = $request->input('search.value');

        $query = SOAPModel::with(['enlistment' => function ($q) {
            $q->select('dCaseNo', 'dPatientFname', 'dPatientMname', 'dPatientLname');
        }])
            ->select('en_CaseNo', 'px_pin', 'dSoapDate', 'report_code', 'report_code2')
            ->where('status', 'CONSULTED');

        $recordsTotal = SOAPModel::where('status', 'CONSULTED')->count();

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('enlistment', function ($q) use ($search) {
                    $q->where('dPatientFname', 'LIKE', "%{$search}%")
                        ->orWhere('dPatientMname', 'LIKE', "%{$search}%")
                        ->orWhere('dPatientLname', 'LIKE', "%{$search}%");
                });
            });
        }

        $recordsFiltered = $query->count();

        $orderMap = [
            '2' => 'dPatientLname',
            '3' => 'dSoapDate'
        ];

        $columnIndex = $request->input('order.0.column');
        $direction = $request->input('order.0.dir', 'asc');

        if (isset($orderMap[$columnIndex])) {
            $column = $orderMap[$columnIndex];

            if ($columnIndex == '2') {
                $query->orderBy(
                    EnlistmentModel::select('dPatientLname')
                        ->whereColumn('dCaseNo', 'en_CaseNo')
                        ->limit(1),
                    $direction
                );
            } else {
                $query->orderBy($column, $direction);
            }
        }

        $records = $query->offset($start)
            ->limit($length)
            ->get();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records
        ]);
    }

    public function generatePatientCodes()
    {
        return response()->json(['success' => true]);
    }

    // Reports
    public function fetchTransactions(Request $request)
    {
        $query = SettlementsModel::query();

        if ($request->filled('filter_start') && $request->filled('filter_end')) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->filter_start)->startOfDay(),
                Carbon::parse($request->filter_end)->endOfDay()
            ]);
        }

        $recordsFiltered = $query->count();
        $start = $request->input('start', 0);
        $length = $request->input('length', 25);
        $records = $query
            ->orderBy('id', 'ASC')
            ->offset($start)
            ->limit($length)
            ->get();

        $recordsTotal = SettlementsModel::count();

        return response()->json([
            'draw' => intval($request->input('draw')),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records
        ]);
    }

    /**
     * Detailed Comment: Fetch comprehensive patient record from pxmasterlist along with
     * latest consultation metadata for viewing and editing modals.
     * Supports search by pxrefno, pincode, or consultationrefno.
     */
    public function fetchPatientDetails(Request $request)
    {
        $pxrefno = $request->input('pxrefno');
        $pincode = $request->input('pincode');
        $consultationrefno = $request->input('consultationrefno');

        $query = PatientMasterlist::query();

        if (!empty($pxrefno)) {
            $query->where('pxrefno', $pxrefno);
        } elseif (!empty($pincode)) {
            $query->where('pincode', $pincode);
        } elseif (!empty($consultationrefno)) {
            $px = ConsultationModel::where('consultationrefno', $consultationrefno)->value('pxrefno');
            if ($px) {
                $query->where('pxrefno', $px);
            } else {
                return response()->json(['success' => false, 'message' => 'Patient record not found.'], 404);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'No patient identifier provided.'], 422);
        }

        $patient = $query->first();

        if (!$patient) {
            return response()->json(['success' => false, 'message' => 'Patient record not found.'], 404);
        }

        // Attach latest consultation and photo if available
        $latestConsult = ConsultationModel::where('pxrefno', $patient->pxrefno)
            ->orderBy('id', 'desc')
            ->first();

        $photoUrl = null;
        $photo = ($latestConsult && $latestConsult->photo_path) ? $latestConsult->photo_path : ($patient->photo_path ?? null);
        if ($photo && !str_contains($photo, 'blank_photo.png')) {
            $filename = basename($photo);
            $photoUrl = url('/patient/photo/' . $filename);
        } else {
            $photoUrl = url('/images/blank_photo.png');
        }

        return response()->json([
            'success' => true,
            'patient' => $patient,
            'latest_consultation' => $latestConsult,
            'photo_url' => $photoUrl
        ]);
    }

    /**
     * Detailed Comment: Admin Patient Update Endpoint.
     * Synchronously updates all pxmasterlist attributes and propagates primary demographic
     * updates (name, gender, birthday, phone, email) to corresponding walk-in consultations.
     */
    public function updatePatient(Request $request)
    {
        $request->validate([
            'pxrefno' => 'required|string',
            'pxfirstname' => 'required|string|max:150',
            'pxlastname' => 'required|string|max:150',
        ]);

        $patient = PatientMasterlist::where('pxrefno', $request->pxrefno)->first();
        if (!$patient) {
            return response()->json(['success' => false, 'message' => 'Patient record not found.'], 404);
        }

        $fullName = trim(implode(' ', array_filter([
            $request->pxlastname . ',',
            $request->pxfirstname,
            $request->pxmidname,
            $request->pxsuffix
        ])));

        // Detailed Comment: Process updated patient photo if uploaded as file or sent as webcam base64
        $photoPath = null;
        if ($request->hasFile('patient_photo') || $request->hasFile('patient_image')) {
            $photoFile = $request->file('patient_photo') ?: $request->file('patient_image');
            $photoPath = $photoFile->store('patient_photo', 'private');
        } elseif ($request->filled('photo_base64')) {
            $base64Data = $request->input('photo_base64');
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $data = substr($base64Data, strpos($base64Data, ',') + 1);
                $type = strtolower($type[1]);
                $data = base64_decode($data);
                if ($data !== false) {
                    $fileName = 'camera_' . uniqid() . '.' . $type;
                    Storage::disk('private')->put('patient_photo/' . $fileName, $data);
                    $photoPath = 'patient_photo/' . $fileName;
                }
            }
        } elseif ($request->filled('photo_path') && !str_contains($request->input('photo_path'), 'blank_photo.png')) {
            $photoPath = $request->input('photo_path');
        }

        $data = [
            'patientname' => $fullName,
            'pxfirstname' => $request->pxfirstname,
            'pxmidname' => $request->pxmidname,
            'pxlastname' => $request->pxlastname,
            'pxsuffix' => $request->pxsuffix,
            'gender' => $request->gender,
            'birthday' => $request->birthday,
            'age' => $request->birthday ? Carbon::parse($request->birthday)->age : $request->age,
            'religion' => $request->religion,
            'nationality' => $request->nationality,
            'mobilenumber' => $request->mobilenumber,
            'emailaddress' => $request->emailaddress,
            'address' => $request->address,
            'streetadrs' => $request->streetadrs,
            'brgy' => $request->brgy,
            'muncity' => $request->muncity,
            'province' => $request->province,
            'zipcode' => $request->zipcode,
            'region' => $request->region,
            'country' => $request->country,
            'phic_pin' => $request->phic_pin,
            'ipd_pincode' => $request->ipd_pincode,
            'ispwd' => $request->has('ispwd') ? ($request->ispwd ? 1 : 0) : $patient->ispwd,
            'senior_idno' => $request->senior_idno,
            'classification' => $request->classification,
            'followupdate' => $request->followupdate,
            'followupcheckup' => $request->followupcheckup,
        ];

        if ($photoPath) {
            $data['photo_path'] = $photoPath;
        }

        $patient->update(array_filter($data, function ($val) {
            return $val !== null;
        }));

        // Propagate demographic and photo changes to walk-in consultations for consistency
        $consultUpdates = [
            'patientname' => $fullName,
            'pxfirstname' => $request->pxfirstname,
            'pxmidname' => $request->pxmidname,
            'pxlastname' => $request->pxlastname,
            'pxsuffix' => $request->pxsuffix,
            'gender' => ($request->gender == 'MALE' || $request->gender == 'M') ? 'M' : 'F',
            'birthday' => $request->birthday,
            'age' => $patient->age,
            'mobilenumber' => $request->mobilenumber,
            'emailaddress' => $request->emailaddress
        ];
        if ($photoPath) {
            $consultUpdates['photo_path'] = $photoPath;
        }
        ConsultationModel::where('pxrefno', $patient->pxrefno)->update($consultUpdates);

        Log::info('Patient masterlist updated by admin', [
            'pxrefno' => $patient->pxrefno,
            'updated_by' => auth()->guard('admin')->user()->adminname ?? 'admin'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Patient updated successfully.',
            'patient' => $patient
        ]);
    }

    /**
     * Detailed Comment: Admin Patient Deletion Endpoint.
     * Removes the patient record from pxmasterlist while preserving consultation history
     * in pxwalkinconsultation for medical, financial, and legal audit trail compliance.
     */
    public function deletePatient(Request $request)
    {
        $request->validate([
            'pxrefno' => 'required|string'
        ]);

        $patient = PatientMasterlist::where('pxrefno', $request->pxrefno)->first();
        if (!$patient) {
            return response()->json(['success' => false, 'message' => 'Patient record not found.'], 404);
        }

        $patientName = $patient->patientname;
        $pxrefno = $patient->pxrefno;
        $patient->delete();

        Log::info('Patient deleted from masterlist by admin', [
            'pxrefno' => $pxrefno,
            'patientname' => $patientName,
            'admin' => auth()->guard('admin')->user()->adminname ?? 'admin'
        ]);

        return response()->json([
            'success' => true,
            'message' => "Patient {$patientName} has been removed from masterlist successfully."
        ]);
    }

    /**
     * Detailed Comment: Updates an existing diagnostic category name identified by its category_refno.
     */
    public function editDiagnosticCategory(Request $request)
    {
        $request->validate([
            'category_refno' => 'required|string',
            'category_name' => 'required|string|max:191'
        ]);

        $catg = DiagnosticsCategoryModel::where('category_refno', $request->category_refno)->first();
        if ($catg) {
            $catg->update([
                'category_name' => $request->category_name
            ]);

            Log::info('Diagnostic category updated by admin', [
                'category_refno' => $request->category_refno,
                'category_name' => $request->category_name
            ]);

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Diagnostic category not found.'], 404);
    }

    /**
     * Detailed Comment: Fetches all HMO masterlist entries with DataTables server-side pagination, searching, and sorting support.
     */
    public function fetchAllHmo(Request $request = null)
    {
        $request = $request ?: request();
        $clientCode = session()->get('clientcode') ?? config('app.clientcode') ?? env('CLIENT_CODE', '122377');

        $baseQuery = HMOModel::query();
        if ($clientCode) {
            $clientCount = (clone $baseQuery)->where('dw_clientcode', $clientCode)->count();
            if ($clientCount > 0) {
                $baseQuery->where('dw_clientcode', $clientCode);
            }
        }

        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 15);
            $globalSearch = trim((string) $request->input('search.value', ''));

            $recordsTotal = (clone $baseQuery)->count();
            $query = clone $baseQuery;

            // Global search
            if (!empty($globalSearch)) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('hmocode', 'like', "%{$globalSearch}%")
                        ->orWhere('hmoname', 'like', "%{$globalSearch}%")
                        ->orWhere('hmotype', 'like', "%{$globalSearch}%")
                        ->orWhere('accre_no', 'like', "%{$globalSearch}%")
                        ->orWhere('hmoaddress', 'like', "%{$globalSearch}%");
                });
            }

            // Column filters
            $colCode = trim((string) $request->input('columns.1.search.value', ''));
            $colName = trim((string) $request->input('columns.2.search.value', ''));
            $colType = trim((string) $request->input('columns.3.search.value', ''));
            $colAccre = trim((string) $request->input('columns.4.search.value', ''));
            $colAddress = trim((string) $request->input('columns.5.search.value', ''));

            if (!empty($colCode)) {
                $query->where('hmocode', 'like', "%{$colCode}%");
            }
            if (!empty($colName)) {
                $query->where('hmoname', 'like', "%{$colName}%");
            }
            // Detailed Comment: Support both picklist regex and direct substring filtering on HMO type
            if (!empty($colType)) {
                $cleaned = trim($colType, '^$()');
                $types = array_filter(explode('|', $cleaned));
                $query->where(function ($q) use ($colType, $types) {
                    if (!empty($types)) {
                        $q->whereIn('hmotype', $types);
                    }
                    $q->orWhere('hmotype', 'like', "%{$colType}%");
                });
            }
            if (!empty($colAccre)) {
                $query->where('accre_no', 'like', "%{$colAccre}%");
            }
            if (!empty($colAddress)) {
                $query->where('hmoaddress', 'like', "%{$colAddress}%");
            }

            $recordsFiltered = (clone $query)->count();

            // Ordering
            $orderCol = (int) $request->input('order.0.column', 2);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'asc')) === 'desc' ? 'desc' : 'asc';
            $sortMap = [
                1 => 'hmocode',
                2 => 'hmoname',
                3 => 'hmotype',
                4 => 'accre_no',
                5 => 'hmoaddress'
            ];
            $sortField = $sortMap[$orderCol] ?? 'hmoname';
            $query->orderBy($sortField, $orderDir);

            if ($length > 0) {
                $query->skip($start)->take($length);
            }

            $data = $query->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
                'hmos' => $data,
                'hmo' => $data,
                'success' => true
            ]);
        }

        $hmos = $baseQuery->orderBy('hmoname', 'ASC')->get();
        return response()->json([
            'success' => true,
            'data' => $hmos,
            'hmo' => $hmos,
            'hmos' => $hmos
        ]);
    }

    /**
     * Detailed Comment: Adds a new HMO masterlist entry with unique hmocode and validation.
     */
    public function addHmo(Request $request)
    {
        $request->validate([
            'hmoname' => 'required|string|max:120',
            'hmotype' => 'required|in:HMO,COMPANY,GOVERNMENT',
        ]);

        $clientCode = session()->get('clientcode') ?? config('app.clientcode') ?? env('CLIENT_CODE', '122377');
        $code = $request->hmocode ?: ('HMO' . Date::now()->format('ymdHis'));

        $hmo = HMOModel::create([
            'dw_clientcode' => $clientCode,
            'hmocode' => $code,
            'hmoname' => $request->hmoname,
            'hmotype' => $request->hmotype,
            'hmoaddress' => $request->hmoaddress ?? '',
            'coacode' => $request->coacode ?? '',
            'accre_no' => $request->accre_no ?? '',
        ]);

        Log::info('New HMO created by admin', ['hmocode' => $code, 'hmoname' => $request->hmoname]);

        return response()->json(['success' => true, 'hmo' => $hmo]);
    }

    /**
     * Detailed Comment: Updates an existing HMO masterlist entry.
     */
    public function editHmo(Request $request)
    {
        $code = $request->code ?: $request->hmocode;

        $hmo = HMOModel::where('hmocode', $code)->first();
        if (!$hmo && $request->id) {
            $hmo = HMOModel::find($request->id);
        }

        if ($hmo) {
            $hmo->update([
                'hmocode' => $request->hmocode ?? $hmo->hmocode,
                'hmoname' => $request->hmo_ename ?? $request->hmoname ?? $hmo->hmoname,
                'hmotype' => $request->hmo_etype ?? $request->hmotype ?? $hmo->hmotype,
                'hmoaddress' => $request->hmoaddress ?? $hmo->hmoaddress,
                'coacode' => $request->coacode ?? $hmo->coacode,
                'accre_no' => $request->accre_no ?? $hmo->accre_no,
            ]);

            Log::info('HMO entry updated by admin', ['hmocode' => $hmo->hmocode]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'HMO record not found.'], 404);
    }

    /**
     * Detailed Comment: Deletes an HMO masterlist record by hmocode or id.
     */
    public function deleteHmo(Request $request)
    {
        $code = $request->code ?: $request->hmocode;
        $hmo = HMOModel::where('hmocode', $code)->first();
        if (!$hmo && $request->id) {
            $hmo = HMOModel::find($request->id);
        }

        if ($hmo) {
            $hmo->delete();
            Log::info('HMO entry deleted by admin', ['hmocode' => $code]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'HMO record not found.'], 404);
    }

    /**
     * Detailed Comment: Fetches all Philippine administrative regions from lib_region PSGC reference table.
     */
    public function getRegions()
    {
        $regions = DB::table('lib_region')
            ->select(['REGION_CODE', 'REGION_DESC', 'PRO_CODE', 'REGION_NAME'])
            ->orderBy('REGION_CODE', 'ASC')
            ->get();

        return response()->json(['success' => true, 'regions' => $regions]);
    }

    /**
     * Detailed Comment: Fetches provinces belonging to a selected region from lib_province using PROCODE.
     */
    public function getProvinces(Request $request)
    {
        $proCode = $request->pro_code ?? $request->region_code;
        if ($proCode !== null && $proCode !== '') {
            $proCode = str_pad($proCode, 2, '0', STR_PAD_LEFT);
        }

        $query = DB::table('lib_province')->select(['PROCODE', 'PROVINCE', 'PROV_NAME']);
        if ($proCode) {
            $query->where('PROCODE', $proCode);
        }

        $provinces = $query->orderBy('PROV_NAME', 'ASC')->get();
        return response()->json(['success' => true, 'provinces' => $provinces]);
    }

    /**
     * Detailed Comment: Fetches municipalities/cities for a province from lib_municipality.
     */
    public function getMunicipalities(Request $request)
    {
        $query = DB::table('lib_municipality')->select(['PROCODE', 'PROVINCE', 'MUNICIPALITY', 'MUN_NAME']);
        if ($request->pro_code) {
            $query->where('PROCODE', str_pad($request->pro_code, 2, '0', STR_PAD_LEFT));
        }
        if ($request->province) {
            $query->where('PROVINCE', $request->province);
        }

        $municipalities = $query->orderBy('MUN_NAME', 'ASC')->get();
        return response()->json(['success' => true, 'municipalities' => $municipalities]);
    }

    /**
     * Detailed Comment: Fetches barangays for a municipality from lib_barangay.
     */
    public function getBarangays(Request $request)
    {
        $query = DB::table('lib_barangay')->select(['PROCODE', 'PROVINCE', 'MUNICIPALITY', 'BARANGAY', 'BRGY_NAME']);
        if ($request->pro_code) {
            $query->where('PROCODE', str_pad($request->pro_code, 2, '0', STR_PAD_LEFT));
        }
        if ($request->province) {
            $query->where('PROVINCE', $request->province);
        }
        if ($request->municipality) {
            $query->where('MUNICIPALITY', $request->municipality);
        }

        $barangays = $query->orderBy('BRGY_NAME', 'ASC')->get();
        return response()->json(['success' => true, 'barangays' => $barangays]);
    }

    /**
     * Detailed Comment: Fetches postal zip code from lib_zipcode for the specified municipality and province.
     */
    public function getZipcode(Request $request)
    {
        $query = DB::table('lib_zipcode');
        if ($request->pro_code) {
            $query->where('PROCODE', str_pad($request->pro_code, 2, '0', STR_PAD_LEFT));
        }
        if ($request->province) {
            $query->where('PROVINCE', $request->province);
        }
        if ($request->municipality) {
            $query->where('MUNICIPALITY', $request->municipality);
        }

        $zipcode = $query->value('ZIP_CODE');
        return response()->json(['success' => true, 'zipcode' => $zipcode ?: '']);
    }

    /**
     * Detailed Comment: Returns list of active consultations/patients for searchable dropdown in billing modal.
     */
    public function fetchActiveConsultations(Request $request)
    {
        $consultations = ConsultationModel::select([
            'consultationrefno',
            'patientname',
            'pincode',
            'docname',
            'docrefno',
            'consultation_date',
            'status'
        ])
        ->whereNotNull('consultationrefno')
        ->where('consultationrefno', '!=', '')
        ->orderBy('id', 'DESC')
        ->limit(100)
        ->get();

        return response()->json(['success' => true, 'consultations' => $consultations]);
    }

    /**
     * Detailed Comment: Fetches all patient billing charges from pxcharges table with DataTables server-side pagination, searching, and sorting.
     */
    public function fetchAdminBillings(Request $request)
    {
        $clientCode = session()->get('clientcode') ?? config('app.clientcode') ?? env('CLIENT_CODE', '122377');

        $baseQuery = \App\Models\PxChargesModel::query();
        if ($clientCode) {
            $baseQuery->where(function ($q) use ($clientCode) {
                $q->where('dw_clientcode', $clientCode)->orWhereNull('dw_clientcode');
            });
        }

        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 15);
            $globalSearch = trim((string) $request->input('search.value', ''));

            $recordsTotal = (clone $baseQuery)->count();
            $query = clone $baseQuery;

            // Global search
            if (!empty($globalSearch)) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('pxname', 'like', "%{$globalSearch}%")
                        ->orWhere('consultationrefno', 'like', "%{$globalSearch}%")
                        ->orWhere('servicename', 'like', "%{$globalSearch}%")
                        ->orWhere('group_category', 'like', "%{$globalSearch}%")
                        ->orWhere('payment_type', 'like', "%{$globalSearch}%")
                        ->orWhere('px_pin', 'like', "%{$globalSearch}%");
                });
            }

            // Detailed Comment: Column filters - using transdate according to pxcharges data dictionary
            $colDate = trim((string) $request->input('columns.1.search.value', ''));
            $colRef = trim((string) $request->input('columns.2.search.value', ''));
            $colPxName = trim((string) $request->input('columns.3.search.value', ''));
            $colService = trim((string) $request->input('columns.4.search.value', ''));
            $colCat = trim((string) $request->input('columns.5.search.value', ''));
            $colPay = trim((string) $request->input('columns.6.search.value', ''));
            $colTotal = trim((string) $request->input('columns.7.search.value', ''));
            $colDisc = trim((string) $request->input('columns.8.search.value', ''));
            $colNet = trim((string) $request->input('columns.9.search.value', ''));

            if (!empty($colDate)) {
                $query->where('transdate', 'like', "%{$colDate}%");
            }
            if (!empty($colRef)) {
                $query->where('consultationrefno', 'like', "%{$colRef}%");
            }
            if (!empty($colPxName)) {
                $query->where('pxname', 'like', "%{$colPxName}%");
            }
            if (!empty($colService)) {
                $query->where('servicename', 'like', "%{$colService}%");
            }
            if (!empty($colCat)) {
                $query->where('group_category', 'like', "%{$colCat}%");
            }
            if (!empty($colPay)) {
                $cleaned = trim($colPay, '^$()');
                $types = array_filter(explode('|', $cleaned));
                $query->where(function ($q) use ($colPay, $types) {
                    if (!empty($types)) {
                        $q->whereIn('payment_type', $types);
                    }
                    $q->orWhere('payment_type', 'like', "%{$colPay}%");
                });
            }
            if (!empty($colTotal)) {
                $query->where('total', 'like', "%{$colTotal}%");
            }
            if (!empty($colDisc)) {
                $query->where('discount', 'like', "%{$colDisc}%");
            }
            if (!empty($colNet)) {
                $query->where('net_total', 'like', "%{$colNet}%");
            }

            $recordsFiltered = (clone $query)->count();

            // Detailed Comment: Directional ordering mapped to real pxcharges schema columns
            $orderCol = (int) $request->input('order.0.column', 1);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
            $sortMap = [
                1 => 'transdate',
                2 => 'consultationrefno',
                3 => 'pxname',
                4 => 'servicename',
                5 => 'group_category',
                6 => 'payment_type',
                7 => 'total',
                8 => 'discount',
                9 => 'net_total'
            ];
            $sortField = $sortMap[$orderCol] ?? 'id';
            $query->orderBy($sortField, $orderDir);

            if ($length > 0) {
                $query->skip($start)->take($length);
            }

            $data = $query->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
                'success' => true
            ]);
        }

        $charges = $baseQuery->orderBy('id', 'DESC')->get();
        return response()->json(['success' => true, 'data' => $charges]);
    }

    /**
     * Detailed Comment: Adds a new patient billing charge to pxcharges with calculated net total and audit timestamps.
     */
    public function addAdminBilling(Request $request)
    {
        $request->validate([
            'servicename' => 'required|string|max:100',
            'retail' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.01',
        ]);

        $clientCode = session()->get('clientcode') ?? config('app.clientcode') ?? env('CLIENT_CODE', '122377');
        $qty = (float) $request->quantity;
        $retail = (float) $request->retail;
        $total = $qty * $retail;
        $discount = (float) ($request->discount ?? 0);
        $netTotal = max(0, $total - $discount);

        $nextId = (int) (\App\Models\PxChargesModel::max('id') ?? 0) + 1;
        $serviceRefNo = $request->servicerefno ?: ('CHG' . Date::now()->format('ymdHis') . rand(10, 99));

        $rawType = strtoupper(trim($request->payment_type ?? 'POCKET'));
        $paymentType = in_array($rawType, ['HMO', 'PHIC', 'POCKET']) ? $rawType : 'POCKET';
        $paymentMethod = $request->paymentmethod ?: ($request->payment_type ?: 'CASH');

        $charge = \App\Models\PxChargesModel::create([
            'id' => $nextId,
            'dw_clientcode' => $clientCode,
            'transactiontype' => $request->transactiontype ?? 'CHARGES',
            'docrefno' => $request->docrefno ?? '',
            'docname' => $request->docname ?? '',
            'transdate' => $request->transdate ? Carbon::parse($request->transdate) : Carbon::now(),
            'servicerefno' => $serviceRefNo,
            'servicename' => $request->servicename,
            'vatable' => $request->vatable ? 1 : 0,
            'retail' => $retail,
            'quantity' => $qty,
            'total' => $total,
            'discount' => $discount,
            'net_total' => $netTotal,
            'paymentrefno' => $request->paymentrefno ?? '',
            'payment_type' => $paymentType,
            'consultationrefno' => $request->consultationrefno ?? '',
            'pxcode_pin' => $request->pxcode_pin ?? '',
            'pxname' => $request->pxname ?? '',
            'group_category' => $request->group_category ?? 'OTHERS',
            'paymentmethod' => $paymentMethod,
            'updatedby' => auth()->guard('admin')->user()->name ?? 'Admin',
            'updated' => Carbon::now(),
        ]);

        Log::info('Admin created new patient billing charge', [
            'id' => $nextId,
            'servicerefno' => $serviceRefNo,
            'consultationrefno' => $request->consultationrefno,
            'net_total' => $netTotal
        ]);

        return response()->json(['success' => true, 'charge' => $charge]);
    }

    /**
     * Detailed Comment: Edits an existing billing charge in pxcharges.
     */
    public function editAdminBilling(Request $request)
    {
        $request->validate([
            'servicename' => 'required|string|max:100',
            'retail' => 'required|numeric|min:0',
            'quantity' => 'required|numeric|min:0.01',
        ]);

        $query = \App\Models\PxChargesModel::query();
        if ($request->id) {
            $query->where('id', $request->id);
        } elseif ($request->servicerefno) {
            $query->where('servicerefno', $request->servicerefno);
        } else {
            return response()->json(['success' => false, 'message' => 'Missing charge identifier.'], 422);
        }

        $charge = $query->first();
        if (!$charge) {
            return response()->json(['success' => false, 'message' => 'Charge record not found.'], 404);
        }

        $qty = (float) $request->quantity;
        $retail = (float) $request->retail;
        $total = $qty * $retail;
        $discount = (float) ($request->discount ?? 0);
        $netTotal = max(0, $total - $discount);

        $rawType = $request->filled('payment_type') ? strtoupper(trim($request->payment_type)) : null;
        $paymentType = $rawType ? (in_array($rawType, ['HMO', 'PHIC', 'POCKET']) ? $rawType : 'POCKET') : $charge->payment_type;
        $paymentMethod = $request->paymentmethod ?: ($request->payment_type ?: $charge->paymentmethod);

        $charge->update([
            'transactiontype' => $request->transactiontype ?? $charge->transactiontype,
            'docrefno' => $request->docrefno ?? $charge->docrefno,
            'docname' => $request->docname ?? $charge->docname,
            'transdate' => $request->transdate ? Carbon::parse($request->transdate) : $charge->transdate,
            'servicename' => $request->servicename,
            'vatable' => $request->has('vatable') ? ($request->vatable ? 1 : 0) : $charge->vatable,
            'retail' => $retail,
            'quantity' => $qty,
            'total' => $total,
            'discount' => $discount,
            'net_total' => $netTotal,
            'paymentrefno' => $request->paymentrefno ?? $charge->paymentrefno,
            'payment_type' => $paymentType,
            'consultationrefno' => $request->consultationrefno ?? $charge->consultationrefno,
            'pxcode_pin' => $request->pxcode_pin ?? $charge->pxcode_pin,
            'pxname' => $request->pxname ?? $charge->pxname,
            'group_category' => $request->group_category ?? $charge->group_category,
            'paymentmethod' => $paymentMethod,
            'updatedby' => auth()->guard('admin')->user()->name ?? 'Admin',
            'updated' => Carbon::now(),
        ]);

        Log::info('Admin updated billing charge', ['id' => $charge->id, 'servicerefno' => $charge->servicerefno]);

        return response()->json(['success' => true]);
    }

    /**
     * Detailed Comment: Deletes a billing charge from pxcharges.
     */
    public function deleteAdminBilling(Request $request)
    {
        $query = \App\Models\PxChargesModel::query();
        if ($request->id) {
            $query->where('id', $request->id);
        } elseif ($request->servicerefno) {
            $query->where('servicerefno', $request->servicerefno);
        } else {
            return response()->json(['success' => false, 'message' => 'Missing charge identifier.'], 422);
        }

        $charge = $query->first();
        if ($charge) {
            $charge->delete();
            Log::info('Admin deleted billing charge', ['id' => $charge->id, 'servicerefno' => $charge->servicerefno]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Charge record not found.'], 404);
    }

    /**
     * Detailed Comment: Fetches all settlement and SOA records from pxsettlements with DataTables server-side pagination, searching, and sorting.
     */
    public function fetchAdminSettlements(Request $request)
    {
        $baseQuery = SettlementsModel::query();

        if ($request->has('draw')) {
            $draw = (int) $request->input('draw', 1);
            $start = (int) $request->input('start', 0);
            $length = (int) $request->input('length', 15);
            $globalSearch = trim((string) $request->input('search.value', ''));

            $recordsTotal = (clone $baseQuery)->count();
            $query = clone $baseQuery;

            // Global search
            if (!empty($globalSearch)) {
                $query->where(function ($q) use ($globalSearch) {
                    $q->where('consultationrefno', 'like', "%{$globalSearch}%")
                        ->orWhere('docname', 'like', "%{$globalSearch}%")
                        ->orWhere('docrefno', 'like', "%{$globalSearch}%")
                        ->orWhere('px_pin', 'like', "%{$globalSearch}%");
                });
            }

            // Column filters
            $colCreated = trim((string) $request->input('columns.1.search.value', ''));
            $colRef = trim((string) $request->input('columns.2.search.value', ''));
            $colDoc = trim((string) $request->input('columns.3.search.value', ''));
            $colGross = trim((string) $request->input('columns.4.search.value', ''));
            $colCash = trim((string) $request->input('columns.5.search.value', ''));
            $colCta = trim((string) $request->input('columns.6.search.value', ''));
            $colHmo = trim((string) $request->input('columns.7.search.value', ''));
            $colPhic = trim((string) $request->input('columns.8.search.value', ''));
            $colPayable = trim((string) $request->input('columns.9.search.value', ''));

            if (!empty($colCreated)) {
                $query->where('created', 'like', "%{$colCreated}%");
            }
            if (!empty($colRef)) {
                $query->where('consultationrefno', 'like', "%{$colRef}%");
            }
            if (!empty($colDoc)) {
                $query->where('docname', 'like', "%{$colDoc}%");
            }
            // Detailed Comment: Column filters mapped to authoritative pxsettlements schema
            if (!empty($colGross)) {
                $query->where('total_gross', 'like', "%{$colGross}%");
            }
            if (!empty($colCash)) {
                $query->where('payment_cash', 'like', "%{$colCash}%");
            }
            if (!empty($colCta)) {
                $query->where('payment_card', 'like', "%{$colCta}%");
            }
            if (!empty($colHmo)) {
                $query->where('less_hmo', 'like', "%{$colHmo}%");
            }
            if (!empty($colPhic)) {
                $query->where('less_phic', 'like', "%{$colPhic}%");
            }
            if (!empty($colPayable)) {
                $query->where('net_payable', 'like', "%{$colPayable}%");
            }

            $recordsFiltered = (clone $query)->count();

            // Detailed Comment: Directional ordering mapped to real pxsettlements columns
            $orderCol = (int) $request->input('order.0.column', 1);
            $orderDir = strtolower((string) $request->input('order.0.dir', 'desc')) === 'asc' ? 'asc' : 'desc';
            $sortMap = [
                1 => 'created',
                2 => 'consultationrefno',
                3 => 'docname',
                4 => 'total_gross',
                5 => 'payment_cash',
                6 => 'payment_card',
                7 => 'less_hmo',
                8 => 'less_phic',
                9 => 'net_payable'
            ];
            $sortField = $sortMap[$orderCol] ?? 'created';
            $query->orderBy($sortField, $orderDir);

            if ($length > 0) {
                $query->skip($start)->take($length);
            }

            $data = $query->get();

            return response()->json([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $data,
                'success' => true
            ]);
        }

        $settlements = $baseQuery->orderBy('created', 'DESC')->get();
        return response()->json(['success' => true, 'data' => $settlements]);
    }

    /**
     * Detailed Comment: Adds or updates a settlement record in pxsettlements from admin console.
     */
    public function addAdminSettlement(Request $request)
    {
        $request->validate([
            'consultationrefno' => 'required|string|max:50',
            'total_gross' => 'required|numeric|min:0',
        ]);

        $gross = (float) $request->total_gross;
        // Detailed Comment: Extract deduction fields including Senior/PWD, PHIC with ICD/RVS, HMO, and special discounts
        $lessSrpwd = (float) ($request->less_srpwd ?? 0);
        $srpwdRefNo = $request->srpwd_refno ?? null;
        $lessPhic = (float) ($request->less_phic ?? $request->phic ?? 0);
        $phicIcdRvs = $request->phic_icd_rvs ?? null;
        $lessHmo = (float) ($request->less_hmo ?? $request->hmo ?? 0);
        $lessDiscount = (float) ($request->less_discount ?? $request->discount ?? 0);
        $discountDescription = $request->discount_description ?? null;
        $netPayable = max(0, $gross - $lessSrpwd - $lessPhic - $lessHmo - $lessDiscount);
        $trxRef = $request->transactionrefno ?: ('TRX' . Date::now()->format('mdYHis'));

        // Detailed Comment: Resolve HMO code and name from authoritative HMO table "hmo_masterlist"
        $hmoInput = $request->hmo_type ?? $request->hmocode ?? null;
        $hmoCode = $hmoInput ?? '';
        $hmoName = $request->hmoname ?? '';
        if (!empty($hmoInput)) {
            $hmoRecord = HMOModel::where('hmocode', $hmoInput)->orWhere('hmoname', $hmoInput)->first();
            if ($hmoRecord) {
                $hmoCode = $hmoRecord->hmocode;
                $hmoName = !empty($request->hmoname) ? $request->hmoname : $hmoRecord->hmoname;
            }
        }

        $settlement = SettlementsModel::updateOrCreate(
            ['consultationrefno' => $request->consultationrefno],
            [
                'transactionrefno' => $trxRef,
                'pincode' => $request->pincode ?? '',
                'docrefno' => $request->docrefno ?? '',
                'docname' => $request->docname ?? '',
                'total_gross' => $gross,
                'less_srpwd' => $lessSrpwd,
                'srpwd_refno' => $srpwdRefNo,
                'less_phic' => $lessPhic,
                'phic_icd_rvs' => $phicIcdRvs,
                'less_hmo' => $lessHmo,
                'less_discount' => $lessDiscount,
                'discount_description' => $discountDescription,
                'net_payable' => $netPayable,
                'payment_cash' => (float) ($request->payment_cash ?? 0),
                'payment_card' => (float) ($request->payment_card ?? 0),
                'cta_type' => $request->cta_type ?? '',
                'hmocode' => $hmoCode,
                'hmoname' => $hmoName,
                'hmo_type' => $hmoName ?: $hmoCode,
                'created' => Carbon::now(),
                'createdby' => auth()->guard('admin')->user()->name ?? 'Admin',
            ]
        );

        Log::info('Admin created consultation settlement', [
            'consultationrefno' => $request->consultationrefno,
            'transactionrefno' => $trxRef,
            'less_srpwd' => $lessSrpwd,
            'less_phic' => $lessPhic,
            'less_hmo' => $lessHmo,
            'hmocode' => $hmoCode,
            'hmoname' => $hmoName,
            'less_discount' => $lessDiscount,
            'net_payable' => $netPayable
        ]);

        return response()->json(['success' => true, 'settlement' => $settlement]);
    }

    /**
     * Detailed Comment: Edits an existing settlement record in pxsettlements.
     */
    public function editAdminSettlement(Request $request)
    {
        $query = SettlementsModel::query();
        if ($request->consultationrefno) {
            $query->where('consultationrefno', $request->consultationrefno);
        } elseif ($request->transactionrefno) {
            $query->where('transactionrefno', $request->transactionrefno);
        } else {
            return response()->json(['success' => false, 'message' => 'Missing settlement identifier.'], 422);
        }

        $settlement = $query->first();
        if (!$settlement) {
            return response()->json(['success' => false, 'message' => 'Settlement record not found.'], 404);
        }

        $gross = (float) ($request->total_gross ?? $settlement->total_gross);
        // Detailed Comment: Support updating deductions and discounts from admin edit modal
        $lessSrpwd = (float) ($request->less_srpwd ?? $settlement->less_srpwd ?? 0);
        $srpwdRefNo = $request->has('srpwd_refno') ? $request->srpwd_refno : $settlement->srpwd_refno;
        $lessPhic = (float) ($request->less_phic ?? $settlement->less_phic ?? 0);
        $phicIcdRvs = $request->has('phic_icd_rvs') ? $request->phic_icd_rvs : $settlement->phic_icd_rvs;
        $lessHmo = (float) ($request->less_hmo ?? $settlement->less_hmo ?? 0);
        $lessDiscount = (float) ($request->less_discount ?? $settlement->less_discount ?? 0);
        $discountDescription = $request->has('discount_description') ? $request->discount_description : $settlement->discount_description;
        $netPayable = max(0, $gross - $lessSrpwd - $lessPhic - $lessHmo - $lessDiscount);

        // Detailed Comment: Resolve HMO code and name from authoritative HMO table "hmo_masterlist"
        $hmoInput = $request->hmo_type ?? $request->hmocode ?? $settlement->hmo_type ?? $settlement->hmocode;
        $hmoCode = $settlement->hmocode ?? '';
        $hmoName = $request->hmoname ?? $settlement->hmoname ?? '';
        if (!empty($hmoInput)) {
            $hmoRecord = HMOModel::where('hmocode', $hmoInput)->orWhere('hmoname', $hmoInput)->first();
            if ($hmoRecord) {
                $hmoCode = $hmoRecord->hmocode;
                $hmoName = !empty($request->hmoname) ? $request->hmoname : $hmoRecord->hmoname;
            } else {
                $hmoCode = $hmoInput;
                $hmoName = $hmoName ?: $hmoInput;
            }
        }

        $settlement->update([
            'pincode' => $request->pincode ?? $settlement->pincode,
            'docname' => $request->docname ?? $settlement->docname,
            'total_gross' => $gross,
            'less_srpwd' => $lessSrpwd,
            'srpwd_refno' => $srpwdRefNo,
            'less_phic' => $lessPhic,
            'phic_icd_rvs' => $phicIcdRvs,
            'less_hmo' => $lessHmo,
            'less_discount' => $lessDiscount,
            'discount_description' => $discountDescription,
            'net_payable' => $netPayable,
            'payment_cash' => (float) ($request->payment_cash ?? $settlement->payment_cash),
            'payment_card' => (float) ($request->payment_card ?? $settlement->payment_card),
            'cta_type' => $request->cta_type ?? $settlement->cta_type,
            'hmocode' => $hmoCode,
            'hmoname' => $hmoName,
            'hmo_type' => $hmoName ?: $hmoCode,
        ]);

        Log::info('Admin updated settlement', ['consultationrefno' => $settlement->consultationrefno]);

        return response()->json(['success' => true]);
    }

    /**
     * Detailed Comment: Deletes a settlement record from pxsettlements.
     */
    public function deleteAdminSettlement(Request $request)
    {
        $query = SettlementsModel::query();
        if ($request->consultationrefno) {
            $query->where('consultationrefno', $request->consultationrefno);
        } elseif ($request->transactionrefno) {
            $query->where('transactionrefno', $request->transactionrefno);
        } else {
            return response()->json(['success' => false, 'message' => 'Missing settlement identifier.'], 422);
        }

        $settlement = $query->first();
        if ($settlement) {
            $settlement->delete();
            Log::info('Admin deleted settlement', ['consultationrefno' => $settlement->consultationrefno]);
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Settlement record not found.'], 404);
    }

    /**
     * Detailed Comment: Generates PhilHealth Tranche 1 or Tranche 2 XML report for selected patient consultations.
     * Delegates XML DOM assembly to AdminService::generateXML and persists transmittal report record.
     */
    public function generateXML(Request $request)
     {
         $request->validate([
             'data' => 'required',
             'type' => 'required|in:first,second'
         ]);

         $clientCode = KayakapProfileModel::first()?->clientcode ?? config('app.client_code', '122377');

         $rawInput = $request->all();
         // Support nested data payload if passed under 'data'
         $params = [
             'data' => isset($rawInput['data']['data']) ? $rawInput['data']['data'] : $rawInput['data'],
             'type' => isset($rawInput['data']['type']) ? $rawInput['data']['type'] : $rawInput['type'],
             'start' => isset($rawInput['data']['start']) ? $rawInput['data']['start'] : ($rawInput['start'] ?? null),
             'end' => isset($rawInput['data']['end']) ? $rawInput['data']['end'] : ($rawInput['end'] ?? null),
         ];

         Log::info('Generating PhilHealth XML report', [
             'type' => $params['type'],
             'record_count' => is_array($params['data']) ? count($params['data']) : 0,
             'clientcode' => $clientCode
         ]);

         try {
             $result = $this->service->generateXML($params, (string)$clientCode);
             return response()->json($result);
         } catch (\Throwable $e) {
             Log::error('PhilHealth XML generation failed', [
                 'error' => $e->getMessage(),
                 'trace' => $e->getTraceAsString()
             ]);
             return response()->json([
                 'success' => false,
                 'message' => 'Failed to generate XML: ' . $e->getMessage()
             ], 500);
         }
     }

    /**
     * Detailed Comment: Fetches an XML document by report code or reference.
     * Looks up XmlTransModel first; if missing or empty, looks up SOAPModel.
     */
    public function fetchXML(Request $request)
    {
        $reference = $request->input('reference') ?? $request->input('report_code');
        if (!$reference) {
            return response()->json(['success' => false, 'message' => 'Reference is required.'], 422);
        }

        $trans = XmlTransModel::where('report_code', $reference)->first();
        if ($trans && !empty($trans->XML_CONTENT)) {
            return response()->json([
                'success' => true,
                'document' => $trans->XML_CONTENT,
                'status' => $trans->status,
                'report_code' => $trans->report_code
            ]);
        }

        // Check if report_code exists in SOAP model
        $soap = SOAPModel::where('report_code', $reference)
            ->orWhere('report_code2', $reference)
            ->first();

        if ($soap) {
            $transByCase = XmlTransModel::where('dw_clientcode', $soap->dw_clientcode)
                ->where('report_code', $reference)
                ->first();
            if ($transByCase && !empty($transByCase->XML_CONTENT)) {
                return response()->json([
                    'success' => true,
                    'document' => $transByCase->XML_CONTENT,
                    'status' => $transByCase->status,
                    'report_code' => $transByCase->report_code
                ]);
            }
        }

        return response()->json(['success' => false, 'message' => 'XML report document not found.'], 404);
    }

    /**
     * Detailed Comment: Confirms and persists saved state or updates XML content for an existing transmittal report.
     */
    public function saveXML(Request $request)
    {
        $reportCode = $request->input('report_code');
        $xmlContent = $request->input('data');

        if ($reportCode) {
            $record = XmlTransModel::where('report_code', $reportCode)->first();
            if ($record) {
                if ($xmlContent && is_string($xmlContent) && str_starts_with(trim($xmlContent), '<')) {
                    $record->update(['XML_CONTENT' => $xmlContent, 'status' => 'SAVED']);
                } else {
                    $record->update(['status' => 'SAVED']);
                }
                return response()->json(['success' => true, 'message' => 'XML report marked as saved.']);
            }
        }

        return response()->json(['success' => true, 'message' => 'XML report processed.']);
    }

    /**
     * Detailed Comment: Encrypts the raw XML content of a generated report using AES-256-CBC
     * with PhilHealth's required payload format (docMimeType, hash, iv, doc).
     */
    public function encryptXML(Request $request)
    {
        $reference = $request->input('reference') ?? $request->input('report_code');
        if (!$reference) {
            return response()->json(['success' => false, 'message' => 'Report reference code is required.'], 422);
        }

        $trans = XmlTransModel::where('report_code', $reference)->first();
        if (!$trans || empty($trans->XML_CONTENT)) {
            return response()->json(['success' => false, 'message' => 'XML report content not found for encryption.'], 404);
        }

        $passphrase = $request->input('passphrase') ?? config('services.philhealth.passphrase', 'kayakapmd');

        try {
            $result = $this->service->encryptXML([
                'document' => $trans->XML_CONTENT,
                'report_code' => $reference,
                'passphrase' => $passphrase
            ]);

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('PhilHealth XML encryption failed', [
                'error' => $e->getMessage(),
                'reference' => $reference
            ]);
            return response()->json(['success' => false, 'message' => 'Encryption failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Detailed Comment: Decrypts previously encrypted XML report payload for auditing or preview.
     */
    public function decryptXML(Request $request)
    {
        $reference = $request->input('reference') ?? $request->input('report_code');
        if (!$reference) {
            return response()->json(['success' => false, 'message' => 'Report reference code is required.'], 422);
        }

        $trans = XmlTransModel::where('report_code', $reference)->first();
        if (!$trans || empty($trans->ENCRYPTED_CONTENT)) {
            return response()->json(['success' => false, 'message' => 'Encrypted payload not found.'], 404);
        }

        $passphrase = $request->input('passphrase') ?? config('services.philhealth.passphrase', 'kayakapmd');

        try {
            $payload = json_decode($trans->ENCRYPTED_CONTENT, true);
            if (!$payload || !isset($payload['doc']) || !isset($payload['iv'])) {
                return response()->json(['success' => false, 'message' => 'Invalid encrypted payload structure.'], 422);
            }

            $hashedPass = hash('sha256', $passphrase, true);
            $key = str_pad(substr($hashedPass, 0, 32), 32, "\0");
            $iv = substr(base64_decode($payload['iv']), 0, 16);
            $encryptedDoc = base64_decode($payload['doc']);

            $decrypted = openssl_decrypt($encryptedDoc, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            if ($decrypted === false) {
                return response()->json(['success' => false, 'message' => 'Decryption failed. Check passphrase.'], 400);
            }

            return response()->json(['success' => true, 'document' => $decrypted]);
        } catch (\Throwable $e) {
            Log::error('PhilHealth XML decryption failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Decryption exception: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Detailed Comment: Retrieves facility accreditation number (accreno) from PCB profile
     * to formulate downloadable XML transmittal filenames according to PhilHealth naming specification.
     */
    public function getXmlData(Request $request)
    {
        $pcb = PCBModel::first();
        $accreno = $pcb?->hciaccreno ?? 'HCI00000000';

        return response()->json([
            'success' => true,
            'data' => $accreno,
            'accreno' => $accreno,
            'pmccno' => $pcb?->pmccno ?? ''
        ]);
    }
}
