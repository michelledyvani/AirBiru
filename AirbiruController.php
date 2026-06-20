<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AirbiruController extends Controller
{
    // Halaman utama / login / register
    public function index(Request $request)
    {
        $msg_daftar = null;
        $msg_login  = null;
        $show_login = false;

        if ($request->isMethod('post')) {
            if ($request->action === 'daftar') {
                $request->validate([
                    'nama' => 'required',
                    'email' => 'required|email',
                    'hp' => 'required',
                    'password' => 'required|min:6',
                ]);

                $cek = DB::table('users')->where('email', $request->email)->first();
                if ($cek) {
                    $msg_daftar = ['type'=>'error','text'=>'Email sudah terdaftar!'];
                } else {
                    DB::table('users')->insert([
                        'nama'=>$request->nama,
                        'email'=>$request->email,
                        'no_hp'=>$request->hp,
                        'password'=>Hash::make($request->password),
                        'role'=>'pelanggan',
                        'tgl_daftar'=>now()
                    ]);
                    $msg_daftar = ['type'=>'success','text'=>'Pendaftaran berhasil! Silakan login.'];
                    $show_login = true;
                }
            } elseif ($request->action === 'login') {
                $user = DB::table('users')->where('email',$request->email)->first();
                if (!$user) {
                    $msg_login = ['type'=>'error','text'=>'Email tidak ditemukan!'];
                } elseif (!Hash::check($request->password, $user->password)) {
                    $msg_login = ['type'=>'error','text'=>'Password salah!'];
                } elseif ($user->role !== $request->login_role) {
                    $msg_login = ['type'=>'error','text'=>'Role tidak sesuai akun'];
                } else {
                    session()->regenerate();
                    session([
                        'user_id'=>$user->id,
                        'user_nama'=>$user->nama,
                        'user_email'=>$user->email,
                        'user_hp'=>$user->no_hp,
                        'user_role'=>$user->role
                    ]);

                    switch ($user->role) {
                        case 'admin': return redirect('/admin-dashboard');
                        case 'driver': return redirect('/driver-dashboard');
                        default: return redirect('/dashboard');
                    }
                }
                $show_login = true;
            }
        }

        return view('airbiru.index', compact('msg_daftar','msg_login','show_login'));
    }

    // Customer Dashboard
    public function dashboard(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        if (session('user_role') === 'admin')  return redirect('/admin-dashboard');
        if (session('user_role') === 'driver') return redirect('/driver-dashboard');

        $uid = session('user_id');
        $msg = null;

        // Ambil stok air
        $stok = DB::table('stok_air')->first();
        $stokLiter = $stok->total_liter ?? 0;
        $batasMinimum = $stok->batas_minimum ?? 50;

        // POST order / laporan
        if ($request->isMethod('post')) {
            if ($request->action === 'tambah_pesanan') {
                $produk = $request->produk ?? '';

                // Volume per galon (liter)
                $volume = str_contains($produk, '19') ? 19 : (str_contains($produk, '5 Liter') ? 5 : 0);

                // Hitung max galon yang bisa dipesan berdasarkan stok aktual dalam LITER
                if ($volume <= 0) {
                    $msg = ['type' => 'error', 'text' => 'Produk tidak valid!'];
                } else {
                    $maxGalon = (int) floor($stokLiter / $volume); // berapa galon max dari stok liter

                    if ($maxGalon <= 0) {
                        $msg = ['type' => 'error', 'text' => "Stok air habis! Tidak bisa memesan $produk saat ini."];
                    } else {
                        $jml = max(1, min($maxGalon, (int) $request->jumlah));
                        $totalLiterDibutuhkan = $jml * $volume;

                        if ($totalLiterDibutuhkan > $stokLiter) {
                            $msg = [
                                'type' => 'error',
                                'text' => "Stok tidak cukup! Stok tersisa $stokLiter L, kebutuhan pesanan $totalLiterDibutuhkan L. Maksimal $maxGalon galon."
                            ];
                        } else {
                            $id = DB::table('pesanan')->insertGetId([
                                'user_id'           => $uid,
                                'nama'              => $request->nama,
                                'telepon'           => $request->telepon,
                                'negara'            => $request->negara ?? 'Indonesia',
                                'provinsi'          => $request->provinsi,
                                'kota'              => $request->kota,
                                'kecamatan'         => $request->kecamatan,
                                'rt_rw'             => $request->rt_rw,
                                'kode_pos'          => $request->kode_pos,
                                'alamat'            => $request->alamat,
                                'deskripsi'         => $request->deskripsi ?? '',
                                'produk'            => $produk,
                                'jumlah'            => $jml,
                                'volume_per_galon'  => $volume,
                                'total_liter'       => $totalLiterDibutuhkan,
                                'jadwal'            => $request->jadwal ?? 'Sekarang (1-2 jam)',
                                'catatan'           => $request->catatan ?? '',
                                'tanggal_pengiriman'=> $request->tanggal_pengiriman ?: null,
                                'jam_pengiriman'    => $request->jam_pengiriman ?: null,
                            ]);

                            if ($id) {
                                // Kurangi stok dalam LITER (bukan jumlah galon)
                                DB::table('stok_air')->decrement('total_liter', $totalLiterDibutuhkan);
                                $msg = ['type' => 'success', 'text' => "Pesanan berhasil! $jml galon ($totalLiterDibutuhkan L) dikurangi dari stok."];
                            } else {
                                $msg = ['type' => 'error', 'text' => 'Gagal menambah pesanan'];
                            }
                        }
                    }
                }
            } elseif ($request->action === 'edit_pesanan') {
                $id = (int)$request->pesanan_id;
                    $cek = DB::table('pesanan')
                        ->where('id', $id)
                        ->where('user_id', $uid)
                        ->first();

                    if (!$cek) {

                        $msg = [
                            'type' => 'error',
                            'text' => 'Pesanan tidak ditemukan!'
                        ];

                    } else {

                        $produk = $request->produk ?? '';

                        $volume = str_contains($produk, '19')
                            ? 19
                            : (str_contains($produk, '5 Liter') ? 5 : 0);

                        DB::table('pesanan')
                            ->where('id', $id)
                            ->where('user_id', $uid)
                            ->update([
                                'nama'               => $request->nama,
                                'telepon'            => $request->telepon,
                                'negara'             => $request->negara,
                                'provinsi'           => $request->provinsi,
                                'kota'               => $request->kota,
                                'kecamatan'          => $request->kecamatan,
                                'rt_rw'              => $request->rt_rw,
                                'kode_pos'           => $request->kode_pos,
                                'alamat'             => $request->alamat,
                                'deskripsi'          => $request->deskripsi,
                                'produk'             => $request->produk,
                                'jumlah'             => (int)$request->jumlah,
                                'volume_per_galon'   => $volume,
                                'total_liter'        => ((int)$request->jumlah * $volume),
                                'jadwal'             => $request->jadwal,
                                'catatan'            => $request->catatan,
                                'tanggal_pengiriman' => $request->tanggal_pengiriman ?: null,
                                'jam_pengiriman'     => $request->jam_pengiriman ?: null,
                            ]);

                        return redirect('/dashboard?tab=order');
                    }
                   
            } elseif ($request->action === 'kirim_laporan') {

    $allowed = [
        'Keterlambatan Pengiriman',
        'Kualitas Air Bermasalah',
        'Galon Bocor/Rusak',
        'Driver Tidak Profesional',
        'Pesanan Salah',
        'Lainnya'
    ];

    if (!in_array(trim($request->kategori), $allowed)) {

        $msg = [
            'type' => 'error',
            'text' => 'Kategori tidak valid!'
        ];

    } elseif (empty(trim($request->deskripsi ?? ''))) {

        $msg = [
            'type' => 'error',
            'text' => 'Deskripsi wajib diisi!'
        ];

    } else {

        $insert = DB::table('laporan')->insert([
            'user_id'    => $uid,
            'no_pesanan' => $request->no_pesanan ?: null,
            'kategori'   => trim($request->kategori),
            'deskripsi'  => trim($request->deskripsi),
        ]);

        if ($insert) {
            $msg = [
                'type' => 'success',
                'text' => 'Laporan berhasil dikirim!'
            ];
        } else {
            $msg = [
                'type' => 'error',
                'text' => 'Gagal menyimpan laporan!'
            ];
        } 
    }
}
}    

        // Variabel tambahan yang dibutuhkan blade
        $nama_user   = session('user_nama', 'Pelanggan');
        $active_tab  = (request('tab') === 'order') ? 'order' : 'dashboard';

        $pesanan     = DB::table('pesanan')->where('user_id',$uid)->orderByDesc('tgl_pesan')->get();
        $laporan     = DB::table('laporan')->where('user_id',$uid)->orderByDesc('tgl_laporan')->get();

        $total_p     = $pesanan->count();
        $total_l     = $laporan->count();
        $cnt_selesai = DB::table('pesanan')->where('user_id',$uid)->where('status','Selesai')->count();
        $cnt_proses  = DB::table('pesanan')->where('user_id',$uid)->where('status','!=','Selesai')->count();

        $edit_pesanan  = null;
        if (request('edit') && ctype_digit((string) request('edit'))) {
            $edit_pesanan = DB::table('pesanan')
                ->where('id', (int) request('edit'))
                ->where('user_id', $uid)
                ->first();
            if ($edit_pesanan) {
                $active_tab = 'order';
                $edit_pesanan = (array) $edit_pesanan;
            }
        }

        $aktif_pesanan = DB::table('pesanan')
            ->where('user_id',$uid)
            ->where('status','!=','Selesai')
            ->orderByDesc('tgl_pesan')
            ->first();
        $aktif_pesanan = $aktif_pesanan ? (array) $aktif_pesanan : null;

        $sc_map = [
            'Diproses'  => 'bg-orange-100 text-orange-700',
            'Disiapkan' => 'bg-blue-100 text-blue-700',
            'Diantar'   => 'bg-cyan-100 text-cyan-700',
            'Tiba'      => 'bg-purple-100 text-purple-700',
            'Selesai'   => 'bg-green-100 text-green-700',
        ];
        $lc_map = [
            'Masuk'    => 'bg-orange-100 text-orange-700',
            'Diproses' => 'bg-blue-100 text-blue-700',
            'Selesai'  => 'bg-blue-100 text-blue-700',
        ];

        return view('airbiru.dashboard', compact(
            'pesanan','laporan','msg','stokLiter','batasMinimum',
            'nama_user','active_tab','total_p','total_l',
            'cnt_selesai','cnt_proses','edit_pesanan','aktif_pesanan',
            'sc_map','lc_map'
        ));
}
    
    // Driver Dashboard
    public function driverDashboard(Request $request)
    {
        if (!session('user_id') || session('user_role') !== 'driver') return redirect('/');

        $uid = session('user_id');

        // Ambil data driver (nama, no_hp, status_driver)
        $driver = DB::table('users')->where('id', $uid)->first();
        $driver_nama   = $driver->nama ?? session('user_nama', 'Driver');
        $driver_no_hp  = $driver->no_hp ?? '';
        $status_driver = $driver->status_driver ?? 'aktif';

        $aktif = DB::table('pesanan')
            ->where('driver_id', $uid)
            ->where('status', '!=', 'Selesai')
            ->orderBy('tgl_pesan')
            ->get();

        $selesai_count = DB::table('pesanan')
            ->where('driver_id', $uid)
            ->where('status', 'Selesai')
            ->count();

        $selesai = DB::table('pesanan')
            ->where('driver_id', $uid)
            ->where('status', 'Selesai')
            ->orderByDesc('tgl_pesan')
            ->limit(10)
            ->get();

        return view('airbiru.driver-dashboard', compact('aktif', 'selesai_count', 'selesai', 'driver_nama', 'driver_no_hp', 'status_driver'));
    }

    public function driverUpdateStatus(Request $request)
    {
        if (!session('user_id') || session('user_role') !== 'driver') return redirect('/');
        $uid = session('user_id');

        // Handle update status_driver (aktif / bertugas / tidak_aktif)
        if ($request->action === 'update_status_driver') {
            $allowed = ['aktif', 'bertugas', 'tidak_aktif'];
            $new_status = $request->status_driver;
            if (!in_array($new_status, $allowed)) {
                return back()->with('error', 'Status tidak valid.');
            }
            DB::table('users')->where('id', $uid)->update(['status_driver' => $new_status]);
            $labels = ['aktif' => 'Siap Menerima Pesanan', 'bertugas' => 'Aktif Bertugas', 'tidak_aktif' => 'Tidak Aktif'];
            return back()->with('success', 'Status Anda diperbarui: ' . ($labels[$new_status] ?? $new_status));
        }

        $pid = (int)$request->pesanan_id;
        $status = $request->status;
        // Driver hanya boleh set Tiba (dari status Diantar)
        $allowed_status = ['Diantar', 'Tiba'];

        if (!in_array($status, $allowed_status)) {
            return back()->with('error', 'Status tidak valid');
        }

        $p = DB::table('pesanan')->where('id', $pid)->where('driver_id', $uid)->first();
        if (!$p) return back()->with('error', 'Pesanan tidak ditemukan');

        // Driver hanya bisa ubah ke Tiba, dan hanya jika status saat ini Diantar
        if ($status === 'Tiba' && $p->status !== 'Diantar') {
            return back()->with('error', 'Pesanan harus berstatus Diantar untuk diubah ke Tiba');
        }

        DB::table('pesanan')->where('id', $pid)->where('driver_id', $uid)->update(['status' => $status]);
        return back()->with('success', "Status diperbarui ke \"$status\"");
    }

    // Admin Dashboard
    public function adminDashboard(Request $request)
    {
        if (!session('user_id') || session('user_role') !== 'admin') return redirect('/');

        $msg = null;

        // Stok
        $stok         = DB::table('stok_air')->first();
        $stokLiter    = $stok->total_liter    ?? 0;
        $batasMinimum = $stok->batas_minimum  ?? 50;
        $estimasi_19L = $stokLiter > 0 ? floor($stokLiter / 19) : 0;
        $estimasi_5L  = $stokLiter > 0 ? floor($stokLiter / 5)  : 0;

        // Handle POST actions
        if ($request->isMethod('post')) {
            $action = $request->action;

            if ($action === 'update_stok') {
                DB::table('stok_air')->update([
                    'total_liter'   => (int) $request->total_liter,
                    'batas_minimum' => (int) $request->batas_minimum,
                ]);
                $msg = ['type' => 'success', 'text' => 'Stok berhasil diperbarui!'];
                // Refresh stok values
                $stok         = DB::table('stok_air')->first();
                $stokLiter    = $stok->total_liter    ?? 0;
                $batasMinimum = $stok->batas_minimum  ?? 50;
                $estimasi_19L = $stokLiter > 0 ? floor($stokLiter / 19) : 0;
                $estimasi_5L  = $stokLiter > 0 ? floor($stokLiter / 5)  : 0;

            } elseif ($action === 'update_status') {
                $pid    = (int) $request->pesanan_id;
                $status = $request->status;
                // Admin hanya bisa set Diproses, Disiapkan, Diantar (bukan Tiba/Selesai)
                $allowed = ['Diproses', 'Disiapkan', 'Diantar'];
                if (in_array($status, $allowed)) {
                    DB::table('pesanan')->where('id', $pid)->update(['status' => $status]);
                    $msg = ['type' => 'success', 'text' => "Status pesanan #$pid diperbarui ke \"$status\"."];
                } else {
                    $msg = ['type' => 'error', 'text' => 'Status tidak valid untuk admin.'];
                }

            } elseif ($action === 'assign_driver') {
                $pid       = (int) $request->pesanan_id;
                $driver_id = (int) $request->driver_id;
                DB::table('pesanan')->where('id', $pid)->update([
                    'driver_id' => $driver_id > 0 ? $driver_id : null,
                ]);
                $msg = ['type' => 'success', 'text' => $driver_id > 0 ? "Driver berhasil ditugaskan." : "Driver dilepas dari pesanan."];

            } elseif ($action === 'update_laporan') {
                $lid    = (int) $request->laporan_id;
                $status = $request->status;
                $allowed = ['Masuk', 'Diproses', 'Selesai'];
                if (in_array($status, $allowed)) {
                    DB::table('laporan')->where('id', $lid)->update(['status' => $status]);
                    $msg = ['type' => 'success', 'text' => "Status laporan #$lid diperbarui."];
                }
            }
        }

        // Statistik
        $stat_pesanan   = DB::table('pesanan')->count();
        $stat_selesai   = DB::table('pesanan')->where('status', 'Selesai')->count();
        $stat_driver    = DB::table('users')->where('role', 'driver')->count();
        $stat_laporan   = DB::table('laporan')->where('status', 'Masuk')->count();
        $stat_pelanggan = DB::table('users')->where('role', 'pelanggan')->count();

        // Daftar pesanan + join nama user & nama driver
        $r_pesanan = DB::table('pesanan as p')
            ->leftJoin('users as u', 'p.user_id', '=', 'u.id')
            ->leftJoin('users as d', function ($join) {
                $join->on('p.driver_id', '=', 'd.id')->where('d.role', '=', 'driver');
            })
            ->select('p.*', 'u.nama as u_nama', 'd.nama as d_nama')
            ->orderByDesc('p.tgl_pesan')
            ->get();

        // Daftar laporan + join nama user
        $r_laporan = DB::table('laporan as l')
            ->leftJoin('users as u', 'l.user_id', '=', 'u.id')
            ->select('l.*', 'u.nama as u_nama')
            ->orderByDesc('l.tgl_laporan')
            ->get();

        // Daftar driver (termasuk status_driver untuk filtering di assign form)
        $drivers_list = json_decode(json_encode(
            DB::table('users')
                ->where('role', 'driver')
                ->select('id', 'nama', 'no_hp', 'status_driver')
                ->orderBy('nama')
                ->get()
        ), true);

        $nama_user = session('user_nama', 'Admin');
        $adm_tab   = in_array($request->tab, ['depot','pesanan','laporan','driver'])
                        ? $request->tab
                        : 'depot';

        $sc_map = [
            'Diproses'  => 'bg-orange-100 text-orange-700',
            'Disiapkan' => 'bg-blue-100 text-blue-700',
            'Diantar'   => 'bg-cyan-100 text-cyan-700',
            'Tiba'      => 'bg-purple-100 text-purple-700',
            'Selesai'   => 'bg-green-100 text-green-700',
        ];
        $lc_map = [
            'Masuk'    => 'bg-orange-100 text-orange-700',
            'Diproses' => 'bg-blue-100 text-blue-700',
            'Selesai'  => 'bg-green-100 text-green-700',
        ];

        return view('airbiru.admin-dashboard', compact(
            'stokLiter', 'batasMinimum', 'estimasi_19L', 'estimasi_5L',
            'msg', 'nama_user', 'adm_tab',
            'stat_pesanan', 'stat_selesai', 'stat_driver', 'stat_laporan', 'stat_pelanggan',
            'r_pesanan', 'r_laporan', 'drivers_list',
            'sc_map', 'lc_map'
        ));
    }

    public function updateStok(Request $request)
    {
        if (!session('user_id') || session('user_role') !== 'admin') return redirect('/');
        DB::table('stok_air')->update([
            'total_liter'=>$request->total_liter,
            'batas_minimum'=>$request->batas_minimum
        ]);
        return back()->with('success','Stok berhasil diperbarui');
    }

    // Detail Pesanan
    public function detailPesanan(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        if (session('user_role') === 'admin')  return redirect('/admin-dashboard');
        if (session('user_role') === 'driver') return redirect('/driver-dashboard');

        $uid = session('user_id');
        $id  = (int) $request->id;

        if (!$id) return redirect('/dashboard?tab=order');

        // Pastikan pesanan milik user yang login
        $pesanan = DB::table('pesanan')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->first();

        if (!$pesanan) return redirect('/dashboard?tab=order');

        $pesanan = (array) $pesanan;

        $sc_map = [
            'Diproses'  => ['class' => 'bg-orange-100 text-orange-700', 'icon' => '⏳'],
            'Disiapkan' => ['class' => 'bg-blue-100 text-blue-700',     'icon' => '📦'],
            'Diantar'   => ['class' => 'bg-cyan-100 text-cyan-700',     'icon' => '🚚'],
            'Tiba'      => ['class' => 'bg-purple-100 text-purple-700', 'icon' => '🏠'],
            'Selesai'   => ['class' => 'bg-green-100 text-green-700',   'icon' => '✅'],
        ];

        $harga_satuan = str_contains($pesanan['produk'], '19') ? 20000 : 8000;
        $total        = $harga_satuan * $pesanan['jumlah'];

        return view('airbiru.detail-pesanan', compact('pesanan', 'sc_map', 'harga_satuan', 'total'));
    }

    // Konfirmasi Selesai oleh pelanggan
    public function konfirmasiSelesai(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        if (session('user_role') !== 'pelanggan') return redirect('/');

        $uid = session('user_id');
        $pid = (int) $request->pesanan_id;

        $pesanan = DB::table('pesanan')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->first();

        if (!$pesanan) {
            return redirect('/dashboard?tab=order')->with('error', 'Pesanan tidak ditemukan.');
        }

        if ($pesanan->status !== 'Tiba') {
            return redirect('/detail-pesanan?id=' . $pid)
                ->with('error', 'Pesanan belum tiba, tidak bisa dikonfirmasi.');
        }

        DB::table('pesanan')->where('id', $pid)->update(['status' => 'Selesai']);

        return redirect('/detail-pesanan?id=' . $pid)
            ->with('success', 'Pesanan berhasil dikonfirmasi sebagai Selesai!');
    }

    // Hapus Pesanan oleh pelanggan (hanya jika status Selesai)
    public function hapusPesanan(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        if (session('user_role') !== 'pelanggan') return redirect('/');

        $uid = session('user_id');
        $pid = (int) $request->pesanan_id;

        $pesanan = DB::table('pesanan')
            ->where('id', $pid)
            ->where('user_id', $uid)
            ->first();

        if (!$pesanan) {
            return redirect('/dashboard?tab=order')->with('error', 'Pesanan tidak ditemukan.');
        }

        if ($pesanan->status !== 'Selesai') {
            return redirect('/dashboard?tab=order')->with('error', 'Pesanan belum selesai, tidak bisa dihapus.');
        }

        DB::table('pesanan')->where('id', $pid)->delete();

        return redirect('/dashboard?tab=order')->with('success', 'Pesanan berhasil dihapus.');
    }

    public function updatePesananDetail(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        DB::table('pesanan')->where('id',$request->id)->update([
            'nama'=>$request->nama,
            'telepon'=>$request->telepon,
            'produk'=>$request->produk,
            'jumlah'=>$request->jumlah,
            'alamat'=>$request->alamat,
            'catatan'=>$request->catatan,
            'tanggal_pengiriman'=>$request->tanggal_pengiriman,
            'jam_pengiriman'=>$request->jam_pengiriman
        ]);
        return back()->with('success','Detail pesanan diperbarui');
    }

    // Profile
    public function profile()
    {
        if (!session('user_id')) return redirect('/');
        $uid  = session('user_id');
        $user = (array) DB::table('users')->where('id', $uid)->first();
        $msg  = null;
        if (session('success')) {
            $msg = ['type' => 'success', 'text' => session('success')];
        } elseif (session('error')) {
            $msg = ['type' => 'error', 'text' => session('error')];
        }
        return view('airbiru.profile', compact('user', 'msg'));
    }

    public function updateProfile(Request $request)
    {
        if (!session('user_id')) return redirect('/');
        $uid = session('user_id');
        DB::table('users')->where('id',$uid)->update([
            'nama'=>$request->nama,
            'no_hp'=>$request->no_hp,
            'email'=>$request->email
        ]);
        return back()->with('success','Profile berhasil diperbarui');
    }
}