<?php

namespace App\Livewire\Portal;

use App\Models\Mahasiswa;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\PembayaranService;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithFileUploads;

    #[Locked]
    public ?int $uploadTagihanId = null;

    public string $sisa = '';
    public string $nominal = '';
    public string $tanggal_bayar = '';
    public string $referensi = '';
    public $bukti = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('portal.lihat'), 403);
    }

    protected function saya(): ?Mahasiswa
    {
        return Mahasiswa::with('programStudi')->where('user_id', auth()->id())->first();
    }

    protected function rules(): array
    {
        return [
            'nominal' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'tanggal_bayar' => ['required', 'date', 'before_or_equal:today'],
            'referensi' => ['nullable', 'string', 'max:100'],
            'bukti' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ];
    }

    protected function messages(): array
    {
        return [
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.gt' => 'Nominal harus lebih dari nol.',
            'nominal.decimal' => 'Nominal maksimal 2 angka di belakang koma.',
            'tanggal_bayar.required' => 'Tanggal bayar wajib diisi.',
            'tanggal_bayar.before_or_equal' => 'Tanggal bayar tidak boleh di masa depan.',
            'bukti.required' => 'Bukti pembayaran wajib diunggah.',
            'bukti.mimes' => 'Bukti harus berupa JPG, PNG, atau PDF.',
            'bukti.max' => 'Ukuran bukti maksimal 2 MB.',
        ];
    }

    public function bukaUpload(int $id, PembayaranService $svc): void
    {
        abort_unless(auth()->user()->can('portal.upload-bukti'), 403);

        $m = $this->saya() ?? abort(404);
        $t = Tagihan::where('mahasiswa_id', $m->id)->find($id) ?? abort(404);

        $sisa = $svc->sisaBisaDibayar($t);
        if ($sisa->isLessThanOrEqualTo(0)) {
            session()->flash('gagal', 'Tagihan ini sudah lunas atau seluruh sisanya sedang menunggu verifikasi.');
            return;
        }

        $this->reset(['referensi', 'bukti']);
        $this->resetValidation();
        $this->uploadTagihanId = $t->id;
        $this->sisa = (string) $sisa;
        $this->nominal = (string) $sisa;
        $this->tanggal_bayar = now()->format('Y-m-d');
    }

    public function kirim(PembayaranService $svc): void
    {
        $user = auth()->user();
        abort_unless($user->can('portal.upload-bukti'), 403);

        $m = $this->saya() ?? abort(404);
        $t = Tagihan::where('mahasiswa_id', $m->id)->find($this->uploadTagihanId) ?? abort(404);

        $data = $this->validate();

        $kunci = 'portal-upload:'.$user->id;
        if (RateLimiter::tooManyAttempts($kunci, 10)) {
            $this->addError('form', 'Terlalu banyak unggahan. Coba lagi nanti.');
            return;
        }
        RateLimiter::hit($kunci, 3600);

        $path = $this->bukti->store('bukti-pembayaran', 'local');

        try {
            $svc->catat([
                'tagihan_id' => $t->id,
                'tanggal_bayar' => $data['tanggal_bayar'],
                'nominal' => $data['nominal'],
                'metode' => 'Transfer Bank',
                'referensi' => $data['referensi'],
            ], $user, $path);
        } catch (DomainException $e) {
            Storage::disk('local')->delete($path);
            $this->addError('form', $e->getMessage());
            return;
        }

        session()->flash('sukses', 'Bukti pembayaran terkirim dan menunggu verifikasi Bagian Keuangan.');
        $this->tutupUpload();
    }

    public function tutupUpload(): void
    {
        $this->reset(['uploadTagihanId', 'sisa', 'nominal', 'referensi', 'bukti']);
        $this->resetValidation();
    }

    public function render(PembayaranService $svc)
    {
        $m = $this->saya();
        $data = ['mhs' => $m];

        if ($m) {
            $tagihan = Tagihan::with('komponen')->where('mahasiswa_id', $m->id)
                ->orderByRaw("status = 'lunas'")->orderBy('jatuh_tempo')->get();
            $tagihan->each(fn ($t) => $t->bisa_bayar = $svc->sisaBisaDibayar($t)->isGreaterThan(0));

            $data += [
                'tagihan' => $tagihan,
                'riwayat' => Pembayaran::with('tagihan.komponen')->where('mahasiswa_id', $m->id)
                    ->orderByDesc('id')->limit(30)->get(),
                'tunggakan' => (float) Tagihan::where('mahasiswa_id', $m->id)->where('status', '!=', Tagihan::LUNAS)
                    ->selectRaw('COALESCE(SUM(nominal - terbayar),0) as t')->value('t'),
                'belumLunas' => $tagihan->where('status', '!=', 'lunas')->count(),
                'menunggu' => Pembayaran::where('mahasiswa_id', $m->id)->where('status', 'pending')->count(),
            ];
        }

        return view('livewire.portal.index', $data);
    }
}
