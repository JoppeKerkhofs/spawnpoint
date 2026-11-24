<?php

namespace App\Http\Controllers;

use App\Api\Abilities;
use App\Models\DevicePairing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Linking an app by signing in: the app opens this page with its code, the person signs in the
 * usual way and allows the device. The token holds what the app asked for, nothing they picked.
 */
class PairDeviceController extends Controller
{
    /**
     * Show which device is asking, and for what.
     */
    public function show(Request $request, string $code): Response
    {
        $pairing = $this->asking($code);
        $requested = $pairing->requested_abilities ?? [];

        return Inertia::render('auth/PairDevice', [
            'pairing' => $pairing === null ? null : [
                'code' => $pairing->code,
                'device_name' => $pairing->device_name,
                'abilities' => array_values(array_filter(
                    array_merge(...array_column(Abilities::groups(), 'abilities')),
                    fn (array $ability): bool => in_array($ability['name'], $requested, true),
                )),
            ],
            // What just happened here, so the page can say so once the pairing is gone.
            'outcome' => $request->session()->get('pair_outcome'),
        ]);
    }

    /**
     * Allow the device, under the name the person gave it.
     */
    public function approve(Request $request, string $code): RedirectResponse
    {
        $data = $request->validate(['device_name' => ['required', 'string', 'max:60']]);
        $pairing = $this->asking($code);

        if ($pairing === null) {
            return to_route('pair.show', $code);
        }

        $pairing->approve($request->user(), $pairing->requested_abilities ?? [], $data['device_name']);

        return to_route('pair.show', $code)->with('pair_outcome', ['state' => 'linked', 'device' => $data['device_name']]);
    }

    /**
     * Not this person's device: the pairing is thrown away and the app is told it is gone.
     */
    public function refuse(string $code): RedirectResponse
    {
        $pairing = $this->asking($code);
        $pairing?->delete();

        return to_route('pair.show', $code)->with('pair_outcome', $pairing === null ? null : ['state' => 'refused', 'device' => $pairing->device_name]);
    }

    /**
     * The waiting pairing behind a code, if its app asked for abilities itself. One started for
     * the code field of the tokens page is not shown here: its abilities are picked there.
     */
    private function asking(string $code): ?DevicePairing
    {
        $pairing = DevicePairing::waiting($code);

        return $pairing?->requested_abilities === null ? null : $pairing;
    }
}
