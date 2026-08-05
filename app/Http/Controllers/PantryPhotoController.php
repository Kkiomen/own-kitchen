<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StorageLocation;
use App\Pantry\PutAway;
use App\Vision\FridgePhoto;
use App\Vision\VisionUnavailable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reading a shelf off a photograph, and then writing down what the cook confirms.
 *
 * Two endpoints on purpose, because they are two different things happening at
 * two different moments: `read` costs a call to a paid model and writes nothing,
 * `confirm` writes and costs nothing. Between them sits a person looking at what
 * was recognised — which is the whole design. A photo that filled the kitchen by
 * itself would be a fridge full of things nobody can see on a shelf.
 */
class PantryPhotoController extends Controller
{
    public function __construct(
        private readonly FridgePhoto $photo,
        private readonly PutAway $putAway,
    ) {}

    public function read(Request $request): RedirectResponse
    {
        $this->abortWhenUnconfigured();

        $request->validate([
            /*
             * Eight megabytes against a phone camera's twelve. The browser
             * shrinks the picture to ~1280 px before it is sent — for the upload,
             * for the model's per-pixel price, and because a shelf is legible
             * long before it is full resolution. This is the backstop for a
             * client that did not, not the expected size.
             */
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:8192'],
        ]);

        $file = $request->file('photo');

        try {
            $spotted = $this->photo->read(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
            );
        } catch (VisionUnavailable $failure) {
            report($failure);

            /*
             * An error on the form rather than a 500: the fridge is open, the
             * phone is in one hand, and the honest answer is "nie udało się,
             * spróbuj jeszcze raz albo wpisz ręcznie" — not a crash screen.
             */
            throw ValidationException::withMessages([
                'photo' => 'Nie udało się odczytać zdjęcia. Spróbuj jeszcze raz albo dodaj produkty ręcznie.',
            ]);
        }

        return back()->with('pantry', ['spotted' => $spotted]);
    }

    /**
     * What the cook ticked, after correcting it. Only products — a line the
     * matcher could not name is either pointed at one on screen or left out;
     * there is nothing else it could honestly become.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $this->abortWhenUnconfigured();

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.location' => ['required', Rule::enum(StorageLocation::class)],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ]);

        $added = 0;

        foreach ($data['items'] as $item) {
            $this->putAway->put($request->user()->id, [
                'ingredient_id' => (int) $item['ingredient_id'],
                'location' => $item['location'],
                // The same rule the form keeps: an amount with no unit is not
                // something this app will write down, so it becomes "some".
                'quantity' => ($item['unit_id'] ?? null) === null ? null : ($item['quantity'] ?? null),
                'unit_id' => $item['unit_id'] ?? null,
            ]);

            $added++;
        }

        return back()->with('pantry', ['added' => $added]);
    }

    /**
     * No key, no feature — and 404 rather than 403, the same way registration
     * and another account's shelves answer. There is nothing here to confirm
     * the existence of.
     */
    private function abortWhenUnconfigured(): void
    {
        abort_if(config('vision.key') === '', 404);
    }
}
