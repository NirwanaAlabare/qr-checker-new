<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class ScanController extends Controller
{
    public function index()
    {
        return view("production-qr");
    }

    // public function scanItem(Request $request)
    // {
    //     if ($request->code) {
    //         $data = null;

    //         if (preg_match('/STK/i', $request->code)) {
    //             // Stocker
    //             $data = DB::table("stocker_input")->leftJoin("master_sb_ws", "master_sb_ws.id_so_det", "=", "stocker_input.so_det_id")->leftJoin("part_detail", "part_detail.id", "=", "stocker_input.part_detail_id")->leftJoin("master_part", "master_part.id", "=", "part_detail.master_part_id")->where("id_qr_stocker", $request->code)->first();
    //         } else {
    //             // Numbering
    //             $codes = explode("_", $request->code);

    //             if (count($codes) > 2) {
    //                 $code = substr($codes[0], 0, 4) . "_" . $codes[1] . "_" . $codes[2];
    //                 $data = DB::table("year_sequence")->leftJoin("master_sb_ws", "master_sb_ws.id_so_det", "=", "year_sequence.so_det_id")->where("id_year_sequence", $code)->first();
    //             } else {
    //                 $code = $request->code;
    //                 $data = DB::table("month_count")->leftJoin("master_sb_ws", "master_sb_ws.id_so_det", "=", "month_count.so_det_id")->where("id_month_year", $code)->first();
    //             }
    //         }

    //         return $data;
    //     }

    //     return null;
    // }


    public function getdataqr(Request $request)
    {
        ini_set('max_execution_time', 3600);
        ini_set('memory_limit', '1024M');

        $codes = explode("_", $request->txtqr);

        if (count($codes) > 2) {
            $qr = substr($codes[0], 0, 4) . "_" . $codes[1] . "_" . $codes[2];
        } else {
            $qr = $request->txtqr;
        }

        $data_master_trans = DB::select("
            select
            m.buyer,
            m.ws,
            m.styleno,
            m.season,
            m.color,
            m.size,
            group_concat(distinct(lot)) lot,
            concat(lebar_marker , ' ', unit_lebar_marker) width,
            count(fd.roll) tot_roll,
            mi.kode,
            COALESCE(f.waktu_selesai, fp.updated_at, fr.updated_at) waktu,
            COALESCE(u.name, '-') name,
            COALESCE(f.no_form, fr.no_form, fp.no_form) no_form,
            m.dest,
            COALESCE(ll.nama_line, ll_bk.nama_line) line_loading,
            DATE_FORMAT(COALESCE(ll.tanggal_loading, ll_bk.tanggal_loading), '%d-%m-%Y') tanggal_loading,
            COALESCE(stk.id_qr_stocker, stk_bk.id_qr_stocker) id_qr_stocker
            from (
            select id_year_sequence, form_cut_id, form_reject_id, form_piece_id, so_det_id, id_qr_stocker, number from year_sequence a
            where id_year_sequence = '$qr'
            ) a
            inner join master_sb_ws m on a.so_det_id = m.id_so_det
            left join form_cut_input f on a.form_cut_id = f.id
            left join form_cut_reject fr on a.form_reject_id = fr.id
            left join form_cut_piece fp on a.form_piece_id = fp.id
            left join marker_input mi on f.id_marker = mi.kode
            left join form_cut_input_detail fd on f.no_form = fd.no_form_cut_input
            left join stocker_input stk on stk.id_qr_stocker = a.id_qr_stocker
            left join stocker_input stk_bk on (stk_bk.form_cut_id = f.id OR stk_bk.form_reject_id = fr.id OR stk_bk.form_piece_id = fp.id) AND stk_bk.so_det_id = a.so_det_id AND CAST(a.number AS UNSIGNED) >= CAST(stk_bk.range_awal AS UNSIGNED) AND CAST(a.number AS UNSIGNED) <= CAST(stk_bk.range_akhir AS UNSIGNED)
            left join loading_line ll on ll.stocker_id = stk.id
            left join loading_line ll_bk on ll_bk.stocker_id = stk_bk.id
            left join users u on f.no_meja = u.id
            group by fd.id_item");

        return json_encode($data_master_trans ? $data_master_trans[0] : '-');
    }

    public function getdataqr_sb(Request $request)
    {
        ini_set('max_execution_time', 3600);
        ini_set('memory_limit', '1024M');

        $codes = explode("_", $request->txtqr);

        if (count($codes) > 2) {
            $qr = substr($codes[0], 0, 4) . "_" . $codes[1] . "_" . $codes[2];
        } else {
            $qr = $request->txtqr;
        }

        $data_sb = DB::connection('mysql_sb')->select("
            select
            DATE_FORMAT(mp.tgl_plan, '%d-%m-%Y') AS tanggal_plan_qc_endline,
            a.sewing_line as qc_endline,
            DATE_FORMAT(a.sewing_in, '%d-%m-%Y') AS tanggal_scan_qc_endline,
            b.tipe as proses_finishing,
            DATE_FORMAT(b.tgl_in, '%d-%m-%Y') AS tanggal_scan_in,
            DATE_FORMAT(b.tgl_out, '%d-%m-%Y') AS tanggal_scan_out,
            DATE_FORMAT(mpf.tgl_plan, '%d-%m-%Y') as tanggal_plan_qc_finishing,
            c.packing_line as qc_finishing_line,
            DATE_FORMAT(c.packing_in, '%d-%m-%Y') AS tanggal_scan_qc_finishing,
            d.po,
            packingpo_line as packing_line,
            DATE_FORMAT(d.packingpo_in, '%d-%m-%Y') AS tanggal_scan_packing_line
            from (
                select o.kode_numbering,u.name sewing_line, master_plan_id, o.created_at sewing_in
                from output_rfts o
                left join user_sb_wip u on o.created_by = u.id
                where o.kode_numbering = '".$qr."'
            ) a
            left join (
                select
                    DATE(output_secondary_in.updated_at) as tgl_in,
                    DATE(output_secondary_out.updated_at) as tgl_out,
                    output_secondary_in.kode_numbering,
                    output_secondary_master.secondary tipe
                from
                    output_secondary_in
                    left join output_rfts on output_rfts.id = output_secondary_in.rft_id
                    left join master_plan on master_plan.id = output_rfts.master_plan_id
                    left join so_det on so_det.id = output_rfts.so_det_id
                    left join so on so.id = so_det.id_so
                    left join act_costing on act_costing.id = so.id_cost
                    left join mastersupplier on mastersupplier.Id_Supplier = act_costing.id_buyer
                    left join userpassword on userpassword.username = output_secondary_in.created_by_username
                    left join output_secondary_out on output_secondary_out.secondary_in_id = output_secondary_in.id
                    left join output_secondary_master on output_secondary_master.id = output_secondary_in.secondary_id
                where
                    output_rfts.status = 'NORMAL' and
                    output_secondary_in.kode_numbering = '".$qr."'
            ) b on a.kode_numbering = b.kode_numbering
            left join (
                select kode_numbering,u.username packing_line, master_plan_id, o.created_at packing_in
                from output_rfts_packing o
                left join userpassword u on o.created_by = u.username
                where kode_numbering = '".$qr."'
            ) c on a.kode_numbering = c.kode_numbering
            left join (
                select o.kode_numbering, o.created_by_line packingpo_line, o.created_at packingpo_in, COALESCE(ppic_master_so.po, (CASE WHEN output_gudang_stok.id IS NOT NULL THEN 'GUDANG STOK' ELSE NULL END)) as po
                from output_rfts_packing_po o
                left join laravel_nds.ppic_master_so on ppic_master_so.id = o.po_id
                left join output_gudang_stok on output_gudang_stok.packing_po_id = o.id
                where o.kode_numbering = '".$qr."'
            ) d on a.kode_numbering = d.kode_numbering
            
            left join master_plan mp on a.master_plan_id = mp.id
            left join master_plan mpf on c.master_plan_id = mpf.id
        ");
        return json_encode($data_sb ? $data_sb[0] : '-');
    }

    public function getdataqr_gambar(Request $request)
    {
        ini_set('max_execution_time', 3600);
        ini_set('memory_limit', '1024M');

        $id = $request->id;
        $data_gambar = DB::connection('mysql_sb')->select("
            select gambar from master_plan where id = '$id'
            ");
        return json_encode($data_gambar[0]);
    }

    public function getdataqr_defect(Request $request)
    {
        ini_set('max_execution_time', 3600);
        ini_set('memory_limit', '1024M');

        $codes = explode("_", $request->txtqr);

        if (count($codes) > 2) {
            $qr = substr($codes[0], 0, 4) . "_" . $codes[1] . "_" . $codes[2];
        } else {
            $qr = $request->txtqr;
        }

        $data_sb = DB::connection('mysql_sb')->select("
            SELECT
                merged.kode_numbering,

                -- 🧵 Sewing Info
                GROUP_CONCAT(DISTINCT merged.sewing_line) AS sewing_line,
                GROUP_CONCAT(DISTINCT merged.defect_status) AS defect_status,
                GROUP_CONCAT(DISTINCT merged.defect_in) AS defect_in,
                GROUP_CONCAT(DISTINCT merged.defect_out) AS defect_out,
                GROUP_CONCAT(DISTINCT merged.defect_type) AS defect_type,
                GROUP_CONCAT(DISTINCT merged.defect_allocation) AS defect_allocation,
                GROUP_CONCAT(DISTINCT merged.external_status) AS external_status,
                GROUP_CONCAT(DISTINCT merged.external_type) AS external_type,
                GROUP_CONCAT(DISTINCT merged.external) AS external,
                GROUP_CONCAT(DISTINCT merged.external_in) AS external_in,
                GROUP_CONCAT(DISTINCT merged.external_out) AS external_out,

                GROUP_CONCAT(DISTINCT merged.d_f_proses_finishing) AS d_f_proses_finishing,
                GROUP_CONCAT(DISTINCT merged.d_f_status_defect) AS d_f_status_defect,
                GROUP_CONCAT(DISTINCT merged.d_f_jenis_defect) AS d_f_jenis_defect,
                GROUP_CONCAT(DISTINCT merged.d_f_alokasi) AS d_f_alokasi,
                GROUP_CONCAT(DISTINCT merged.d_f_tanggal_plan) AS d_f_tanggal_plan,
                GROUP_CONCAT(DISTINCT merged.d_f_defect_finishing_in) AS d_f_defect_finishing_in,
                GROUP_CONCAT(DISTINCT merged.d_f_defect_finishing_out) AS d_f_defect_finishing_out,
                GROUP_CONCAT(DISTINCT merged.d_f_external) AS d_f_external,
                GROUP_CONCAT(DISTINCT merged.d_f_external_status) AS d_f_external_status,
                GROUP_CONCAT(DISTINCT merged.d_f_external_in) AS d_f_external_in,
                GROUP_CONCAT(DISTINCT merged.d_f_external_out) AS d_f_external_out,

                -- 📦 Packing Info
                GROUP_CONCAT(DISTINCT merged.packing_line) AS packing_line,
                GROUP_CONCAT(DISTINCT merged.packing_defect_status) AS packing_defect_status,
                GROUP_CONCAT(DISTINCT merged.packing_defect_in) AS packing_defect_in,
                GROUP_CONCAT(DISTINCT merged.packing_defect_out) AS packing_defect_out,
                GROUP_CONCAT(DISTINCT merged.packing_defect_type) AS packing_defect_type,
                GROUP_CONCAT(DISTINCT merged.packing_defect_allocation) AS packing_defect_allocation,
                GROUP_CONCAT(DISTINCT merged.packing_external_status) AS packing_external_status,
                GROUP_CONCAT(DISTINCT merged.packing_external_type) AS packing_external_type,
                GROUP_CONCAT(DISTINCT merged.packing_external) AS packing_external,
                GROUP_CONCAT(DISTINCT merged.packing_external_in) AS packing_external_in,
                GROUP_CONCAT(DISTINCT merged.packing_external_out) AS packing_external_out,

                -- 📅 Master Plan Info
                mp.id AS master_plan_id,
                mpf.id AS master_plan_id_packing,
                mp.tgl_plan,
                mpf.tgl_plan as tgl_plan_packing,
                DATE_FORMAT(mp.tgl_plan, '%d-%m-%Y') AS tgl_plan_fix,
                DATE_FORMAT(mpf.tgl_plan, '%d-%m-%Y') AS tgl_plan_fix_packing

            FROM (
                    -- 🔹 Sewing data
                    SELECT
                            o.kode_numbering,
                            us.username AS sewing_line,
                            o.defect_status,
                            o.created_at AS defect_in,
                            CASE WHEN o.defect_status = 'reworked' THEN o.updated_at ELSE NULL END AS defect_out,
                            dt.defect_type,
                            dt.allocation AS defect_allocation,
                            dio.STATUS AS external_status,
                            dio.type AS external_type,
                            dio.output_type AS external,
                            dio.created_at AS external_in,
                            dio.reworked_at AS external_out,
                            o.master_plan_id as plan,

                            NULL AS d_f_proses_finishing,
                            NULL AS d_f_status_defect,
                            NULL AS d_f_jenis_defect,
                            NULL AS d_f_alokasi,
                            NULL AS d_f_tanggal_plan,
                            NULL AS d_f_defect_finishing_in,
                            NULL AS d_f_defect_finishing_out,
                            NULL AS d_f_external,
                            NULL AS d_f_external_status,
                            NULL AS d_f_external_in,
                            NULL AS d_f_external_out,

                            -- Packing fields NULLed
                            NULL AS packing_line,
                            NULL AS packing_defect_status,
                            NULL AS packing_defect_in,
                            NULL AS packing_defect_out,
                            NULL AS packing_defect_type,
                            NULL AS packing_defect_allocation,
                            NULL AS packing_external_status,
                            NULL AS packing_external_type,
                            NULL AS packing_external,
                            NULL AS packing_external_in,
                            NULL AS packing_external_out,
                            NULL as packing_plan
                    FROM output_defects o
                    LEFT JOIN output_defect_types dt ON dt.id = o.defect_type_id
                    LEFT JOIN output_defect_in_out dio ON dio.defect_id = o.id AND dio.output_type = 'qc'
                    LEFT JOIN user_sb_wip u ON o.created_by = u.id
                    LEFT JOIN userpassword us ON us.line_id = u.line_id
                    WHERE o.kode_numbering = '".$qr."'

                    UNION

                    select
                        output_secondary_out_defect.kode_numbering,
                        NULL AS sewing_line,
                        NULL AS defect_status,
                        NULL AS defect_in,
                        NULL AS defect_out,
                        NULL AS defect_type,
                        NULL AS defect_allocation,
                        NULL AS external_status,
                        NULL AS external_type,
                        NULL AS external,
                        NULL AS external_in,
                        NULL AS external_out,
                        NULL AS plan,

                        output_secondary_master.secondary AS d_f_proses_finishing,
                        UPPER(output_secondary_out_defect.status) AS d_f_status_defect,
                        output_defect_types.defect_type AS d_f_jenis_defect,
                        output_defect_types.allocation AS d_f_alokasi,
                        master_plan.tgl_plan AS d_f_tanggal_plan,
                        output_secondary_out_defect.created_at AS d_f_defect_finishing_in,
                        CASE WHEN output_secondary_out_defect.status = 'reworked' THEN output_secondary_out_defect.updated_at ELSE NULL END AS d_f_defect_finishing_out,
                        dio.output_type AS d_f_external,
                        dio.STATUS AS d_f_external_status,
                        dio.created_at AS d_f_external_in,
                        dio.reworked_at AS d_f_external_out,

                        NULL AS packing_line,
                        NULL AS packing_defect_status,
                        NULL AS packing_defect_in,
                        NULL AS packing_defect_out,
                        NULL AS packing_defect_type,
                        NULL AS packing_defect_allocation,
                        NULL AS packing_external_status,
                        NULL AS packing_external_type,
                        NULL AS packing_external,
                        NULL AS packing_external_in,
                        NULL AS packing_external_out,
                        NULL as packing_plan
                    from
                        output_secondary_out_defect
                        left join output_secondary_out on output_secondary_out.id = output_secondary_out_defect.secondary_out_id
                        left join output_secondary_in on output_secondary_in.id = output_secondary_out.secondary_in_id
                        left join output_rfts on output_rfts.id = output_secondary_in.rft_id
                        left join master_plan on master_plan.id = output_rfts.master_plan_id
                        left join so_det on so_det.id = output_rfts.so_det_id
                        left join so on so.id = so_det.id_so
                        left join act_costing on act_costing.id = so.id_cost
                        left join mastersupplier on mastersupplier.Id_Supplier = act_costing.id_buyer
                        left join userpassword on userpassword.username = output_secondary_out_defect.created_by_username
                        left join output_defect_types on output_defect_types.id = output_secondary_out_defect.defect_type_id
                        left join output_secondary_master on output_secondary_master.id = output_secondary_in.secondary_id
                        LEFT JOIN output_defect_in_out dio ON dio.defect_id = output_secondary_out_defect.id AND dio.output_type = 'finishing_proses'
                    WHERE output_secondary_out_defect.kode_numbering = '".$qr."'

                    UNION

                    -- 🔹 Packing data
                    SELECT
                            op.kode_numbering,
                            NULL AS sewing_line,
                            NULL AS defect_status,
                            NULL AS defect_in,
                            NULL AS defect_out,
                            NULL AS defect_type,
                            NULL AS defect_allocation,
                            NULL AS external_status,
                            NULL AS external_type,
                            NULL AS external,
                            NULL AS external_in,
                            NULL AS external_out,
                            NULL AS plan,

                            NULL AS d_f_proses_finishing,
                            NULL AS d_f_status_defect,
                            NULL AS d_f_jenis_defect,
                            NULL AS d_f_alokasi,
                            NULL AS d_f_tanggal_plan,
                            NULL AS d_f_defect_finishing_in,
                            NULL AS d_f_defect_finishing_out,
                            NULL AS d_f_external,
                            NULL AS d_f_external_status,
                            NULL AS d_f_external_in,
                            NULL AS d_f_external_out,

                            up.username AS packing_line,
                            op.defect_status AS packing_defect_status,
                            op.created_at AS packing_defect_in,
                            CASE WHEN op.defect_status = 'reworked' THEN op.updated_at ELSE NULL END AS packing_defect_out,
                            dtt.defect_type AS packing_defect_type,
                            dtt.allocation AS packing_defect_allocation,
                            diop.STATUS AS packing_external_status,
                            diop.type AS packing_external_type,
                            diop.output_type AS packing_external,
                            diop.created_at AS packing_external_in,
                            diop.reworked_at AS packing_external_out,
                            op.master_plan_id as packing_plan
                    FROM output_defects_packing op
                    LEFT JOIN output_defect_types dtt ON dtt.id = op.defect_type_id
                    LEFT JOIN output_defect_in_out diop ON diop.defect_id = op.id AND diop.output_type = 'packing'
                    LEFT JOIN userpassword up ON op.created_by = up.username
                    WHERE op.kode_numbering = '".$qr."'
            ) AS merged

            LEFT JOIN master_plan mp ON mp.id = merged.plan
            LEFT JOIN master_plan mpf ON mpf.id = merged.packing_plan
            GROUP BY merged.kode_numbering
        ");

        return json_encode($data_sb ? $data_sb[0] : '-');
    }

    public function getdataqr_reject(Request $request)
    {
        ini_set('max_execution_time', 3600);
        ini_set('memory_limit', '1024M');

        $codes = explode("_", $request->txtqr);

        if (count($codes) > 2) {
            $qr = substr($codes[0], 0, 4) . "_" . $codes[1] . "_" . $codes[2];
        } else {
            $qr = $request->txtqr;
        }

        $data_sb = DB::connection('mysql_sb')->select("
            SELECT
                merged.kode_numbering,

                -- 🧵 Sewing Info
                GROUP_CONCAT(DISTINCT merged.sewing_line) AS sewing_line,
                GROUP_CONCAT(DISTINCT merged.reject_status) AS reject_status,
                GROUP_CONCAT(DISTINCT merged.reject_in) AS reject_in,
                GROUP_CONCAT(DISTINCT merged.defect_type) AS defect_type,
                GROUP_CONCAT(DISTINCT merged.defect_allocation) AS defect_allocation,
                
                GROUP_CONCAT(DISTINCT merged.finishing_line) AS finishing_line,
                GROUP_CONCAT(DISTINCT merged.finishing_reject_status) AS finishing_reject_status,
                GROUP_CONCAT(DISTINCT merged.finishing_reject_in) AS finishing_reject_in,
                GROUP_CONCAT(DISTINCT merged.finishing_defect_type) AS finishing_defect_type,
                GROUP_CONCAT(DISTINCT merged.finishing_defect_allocation) AS finishing_defect_allocation,
                
                -- 📦 Packing Info
                GROUP_CONCAT(DISTINCT merged.packing_line) AS packing_line,
                GROUP_CONCAT(DISTINCT merged.packing_reject_status) AS packing_reject_status,
                GROUP_CONCAT(DISTINCT merged.packing_reject_in) AS packing_reject_in,
                GROUP_CONCAT(DISTINCT merged.packing_defect_type) AS packing_defect_type,
                GROUP_CONCAT(DISTINCT merged.packing_defect_allocation) AS packing_defect_allocation,

                -- 📅 Master Plan Info
                mp.id AS master_plan_id,
                mp.tgl_plan,
                DATE_FORMAT(mp.tgl_plan, '%d-%m-%Y') AS tgl_plan_fix,

                qc_reject_in,
                qc_reject_status,
                qc_reject_process,
                qc_reject_grade,
                qc_reject_type,
                qc_reject_area,
                qc_reject_out,
                qc_reject_out_trans,
                qc_reject_out_tujuan,
                qc_reject_out_sewing
            FROM (
                    -- 🔹 Sewing data
                    SELECT
                        o.kode_numbering,
                        CONCAT('QC ', us.username) AS sewing_line,
                        o.reject_status,
                        o.created_at AS reject_in,
                        dt.defect_type,
                        dt.allocation AS defect_allocation,

                        NULL AS finishing_line,
                        NULL AS finishing_reject_status,
                        NULL AS finishing_reject_in,
                        NULL AS finishing_defect_type,
                        NULL AS finishing_defect_allocation,

                        NULL AS packing_line,
                        NULL AS packing_reject_status,
                        NULL AS packing_reject_in,
                        NULL AS packing_defect_type,
                        NULL AS packing_defect_allocation,

                        ori.created_at AS qc_reject_in,
                        ori.status AS qc_reject_status,
                        ori.process AS qc_reject_process,
                        ori.grade AS qc_reject_grade,
                        GROUP_CONCAT(DISTINCT qcdt.defect_type) AS qc_reject_type,
                        GROUP_CONCAT(DISTINCT qcda.defect_area) AS qc_reject_area,
                        COALESCE(oro.updated_at, oro.created_at, oro.tanggal) qc_reject_out,
                        oro.no_transaksi qc_reject_out_trans,
                        oro.tujuan qc_reject_out_tujuan,
                        ori.updated_at AS qc_reject_out_sewing,

                        o.master_plan_id
                    FROM output_rejects o
                    LEFT JOIN output_defect_types dt ON dt.id = o.reject_type_id
                    LEFT JOIN user_sb_wip u ON o.created_by = u.id
                    LEFT JOIN userpassword us ON us.line_id = u.line_id
                    LEFT JOIN output_reject_in as ori on o.id = ori.reject_id AND ori.output_type = 'qc'
                    LEFT JOIN output_reject_in_detail as orid on orid.reject_in_id = ori.id
                    LEFT JOIN output_defect_types as qcdt on qcdt.id = orid.reject_type_id
                    LEFT JOIN output_defect_areas as qcda on qcda.id = orid.reject_area_id
                    LEFT JOIN output_reject_out_detail as orod on orod.reject_in_id = ori.id
                    LEFT JOIN output_reject_out as oro on oro.id = orod.reject_out_id
                    WHERE o.kode_numbering = '".$qr."'

                    UNION

                    select
                        output_secondary_out_reject.kode_numbering,
                        NULL AS sewing_line,
                        NULL AS reject_status,
                        NULL AS reject_in,
                        NULL AS defect_type,
                        NULL AS defect_allocation,

                        CONCAT('FINISHING PROSES ', userpassword.username) AS finishing_line,
                        output_secondary_out_reject.status AS finishing_reject_status,
                        output_secondary_out_reject.created_at AS finishing_reject_in,
                        output_defect_types.defect_type AS finishing_defect_type,
                        output_defect_types.allocation AS finishing_defect_allocation,

                        NULL AS packing_line,
                        NULL AS packing_reject_status,
                        NULL AS packing_reject_in,
                        NULL AS packing_defect_type,
                        NULL AS packing_defect_allocation,

                        NULL AS qc_reject_in,
                        NULL AS qc_reject_status,
                        NULL AS qc_reject_process,
                        NULL AS qc_reject_grade,
                        NULL AS qc_reject_type,
                        NULL AS qc_reject_area,
                        NULL AS qc_reject_out,
                        NULL AS qc_reject_out_trans,
                        NULL AS qc_reject_out_tujuan,
                        NULL AS qc_reject_out_sewing,

                        output_rfts.master_plan_id
                    from
                        output_secondary_out_reject
                        left join output_secondary_out on output_secondary_out.id = output_secondary_out_reject.secondary_out_id
                        left join output_secondary_in on output_secondary_in.id = output_secondary_out.secondary_in_id
                        left join output_rfts on output_rfts.id = output_secondary_in.rft_id
                        left join master_plan on master_plan.id = output_rfts.master_plan_id
                        left join so_det on so_det.id = output_rfts.so_det_id
                        left join so on so.id = so_det.id_so
                        left join act_costing on act_costing.id = so.id_cost
                        left join mastersupplier on mastersupplier.Id_Supplier = act_costing.id_buyer
                        left join userpassword on userpassword.username = output_secondary_out_reject.created_by_username
                        left join output_defect_types on output_defect_types.id = output_secondary_out_reject.defect_type_id
                        left join output_secondary_master on output_secondary_master.id = output_secondary_in.secondary_id
                    where
                        output_secondary_out_reject.kode_numbering = '".$qr."'

                    UNION

                    -- 🔹 Packing data
                    SELECT
                        o.kode_numbering,
                        NULL AS sewing_line,
                        NULL AS reject_status,
                        NULL AS reject_in,
                        NULL AS defect_type,
                        NULL AS defect_allocation,

                        NULL AS finishing_line,
                        NULL AS finishing_reject_status,
                        NULL AS finishing_reject_in,
                        NULL AS finishing_defect_type,
                        NULL AS finishing_defect_allocation,

                        CONCAT('FINISHING ', us.username) AS packing_line,
                        o.reject_status AS packing_reject_status,
                        o.created_at AS packing_reject_in,
                        dt.defect_type AS packing_defect_type,
                        dt.allocation AS packing_defect_allocation,

                        ori.created_at AS qc_reject_in,
                        ori.status AS qc_reject_status,
                        ori.process AS qc_reject_process,
                        ori.grade AS qc_reject_grade,
                        GROUP_CONCAT(DISTINCT qcdt.defect_type) AS qc_reject_type,
                        GROUP_CONCAT(DISTINCT qcda.defect_area) AS qc_reject_area,
                        COALESCE(oro.updated_at, oro.created_at, oro.tanggal) qc_reject_out,
                        oro.no_transaksi qc_reject_out_trans,
                        oro.tujuan qc_reject_out_tujuan,
                        ori.updated_at AS qc_reject_out_sewing,

                        o.master_plan_id
                    FROM output_rejects_packing o
                    LEFT JOIN output_defect_types dt ON dt.id = o.reject_type_id
                    LEFT JOIN userpassword us ON us.username = o.created_by
                    LEFT JOIN output_reject_in as ori on o.id = ori.reject_id AND ori.output_type = 'packing'
                    LEFT JOIN output_reject_in_detail as orid on orid.reject_in_id = ori.id
                    LEFT JOIN output_defect_types as qcdt on qcdt.id = orid.reject_type_id
                    LEFT JOIN output_defect_areas as qcda on qcda.id = orid.reject_area_id
                    LEFT JOIN output_reject_out_detail as orod on orod.reject_in_id = ori.id
                    LEFT JOIN output_reject_out as oro on oro.id = orod.reject_out_id
                    WHERE o.kode_numbering = '".$qr."'

                    UNION

                    -- 🔹 Reject data
                    SELECT
                        ori.kode_numbering,
                        CONCAT(UPPER(CASE WHEN ori.output_type = 'PACKING' THEN 'FINISHING' ELSE ori.output_type END), ' ' , us.username) AS sewing_line,
                        ori.type as reject_status,
                        ori.created_at AS reject_in,
                        dt.defect_type,
                        dt.allocation AS defect_allocation,

                        NULL AS finishing_line,
                        NULL AS finishing_reject_status,
                        NULL AS finishing_reject_in,
                        NULL AS finishing_defect_type,
                        NULL AS finishing_defect_allocation,

                        NULL AS packing_line,
                        NULL AS packing_reject_status,
                        NULL AS packing_reject_in,
                        NULL AS packing_defect_type,
                        NULL AS packing_defect_allocation,

                        ori.created_at AS qc_reject_in,
                        ori.status AS qc_reject_status,
                        ori.process AS qc_reject_process,
                        ori.grade AS qc_reject_grade,
                        GROUP_CONCAT(DISTINCT qcdt.defect_type) AS qc_reject_type,
                        GROUP_CONCAT(DISTINCT qcda.defect_area) AS qc_reject_area,
                        COALESCE(oro.updated_at, oro.created_at, oro.tanggal) qc_reject_out,
                        oro.no_transaksi qc_reject_out_trans,
                        oro.tujuan qc_reject_out_tujuan,
                        ori.updated_at AS qc_reject_out_sewing,

                        ori.master_plan_id
                    FROM output_reject_in ori
                    LEFT JOIN output_defect_types dt ON dt.id = ori.reject_type_id
                    LEFT JOIN userpassword us ON us.line_id = ori.line_id
                    LEFT JOIN output_reject_in_detail as orid on orid.reject_in_id = ori.id
                    LEFT JOIN output_defect_types as qcdt on qcdt.id = orid.reject_type_id
                    LEFT JOIN output_defect_areas as qcda on qcda.id = orid.reject_area_id
                    LEFT JOIN output_reject_out_detail as orod on orod.reject_in_id = ori.id
                    LEFT JOIN output_reject_out as oro on oro.id = orod.reject_out_id
                    WHERE ori.kode_numbering = '".$qr."'
                    GROUP BY
                        ori.id
            ) AS merged
            LEFT JOIN
                master_plan mp ON mp.id = merged.master_plan_id
            WHERE
                merged.kode_numbering is not null
            GROUP BY
                merged.kode_numbering
        ");

        return json_encode($data_sb ? $data_sb[0] : '-');
    }
}
