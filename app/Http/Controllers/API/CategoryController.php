<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = DB::table('asset_categories')->get()
            ->map(function ($item) {
                $rawId = $item->_id ?? ($item->id ?? null);
                $id = '';

                if (is_object($rawId)) {
                    if (isset($rawId->{'$oid'})) {
                        $id = (string) $rawId->{'$oid'};
                    } elseif (method_exists($rawId, '__toString')) {
                        $id = (string) $rawId;
                    } else {
                        $id = trim((string) json_encode($rawId));
                    }
                } elseif ($rawId !== null) {
                    $id = (string) $rawId;
                }

                $symbols = collect($item->symbols ?? [])
                    ->map(function ($symbol) {
                        return strtoupper(trim((string) $symbol));
                    })
                    ->filter(function ($symbol) {
                        return $symbol !== '';
                    })
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'id' => $id,
                    'name' => trim((string) ($item->name ?? '')),
                    'symbols' => $symbols,
                    'target_pct' => round((float) ($item->target_pct ?? 0), 4),
                    'symbol_targets' => is_array($item->symbol_targets ?? null) ? array_map(function ($v) {
                        return max(0, (float) $v);
                    }, $item->symbol_targets) : [],
                ];
            })
            ->sortBy(function ($item) {
                return mb_strtolower(trim((string) ($item['name'] ?? '')));
            })
            ->values();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            'name' => 'required|string|max:80',
            'target_pct' => 'nullable|numeric|min:0|max:100',
            'symbol_targets' => 'nullable|array',
            'symbol_targets.*' => 'numeric|min:0|max:100',
            'symbols' => 'nullable|array',
            'symbols.*' => 'string|max:30',
        ]);

        $name = trim((string) $v['name']);
        if ($name === '') {
            return response()->json(['status' => 'error', 'message' => '类别名称不能为空'], 422);
        }

        $exists = DB::table('asset_categories')->get()->first(function ($item) use ($name) {
            return mb_strtolower(trim((string) ($item->name ?? ''))) === mb_strtolower($name);
        });

        if ($exists) {
            return response()->json(['status' => 'error', 'message' => '类别已存在'], 422);
        }

        $symbols = collect($v['symbols'] ?? [])
            ->map(function ($symbol) {
                return strtoupper(trim((string) $symbol));
            })
            ->filter(function ($symbol) {
                return $symbol !== '';
            })
            ->unique()
            ->values()
            ->all();

        DB::table('asset_categories')->insert([
            'name' => $name,
            'symbols' => $symbols,
            'target_pct' => max(0, (float) ($v['target_pct'] ?? 0)),
            'symbol_targets' => is_array($v['symbol_targets'] ?? null) ? $v['symbol_targets'] : [],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = DB::table('asset_categories')->get()->first(function ($item) use ($name) {
            return mb_strtolower(trim((string) ($item->name ?? ''))) === mb_strtolower($name);
        });

        $createdId = '';
        if ($created) {
            $rawId = $created->_id ?? ($created->id ?? null);
            if (is_object($rawId)) {
                if (isset($rawId->{'$oid'})) {
                    $createdId = (string) $rawId->{'$oid'};
                } elseif (method_exists($rawId, '__toString')) {
                    $createdId = (string) $rawId;
                }
            } elseif ($rawId !== null) {
                $createdId = (string) $rawId;
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $createdId,
                'name' => $name,
                'target_pct' => max(0, (float) ($v['target_pct'] ?? 0)),
                'symbols' => $symbols,
                'symbol_targets' => is_array($v['symbol_targets'] ?? null) ? $v['symbol_targets'] : [],
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $v = $request->validate([
            'name' => 'nullable|string|max:80',
            'symbols' => 'array',
            'symbols.*' => 'string|max:30',
            'target_pct' => 'nullable|numeric|min:0|max:100',
            'symbol_targets' => 'nullable|array',
            'symbol_targets.*' => 'numeric|min:0|max:100',
        ]);

        $symbols = collect($v['symbols'] ?? [])
            ->map(function ($symbol) {
                return strtoupper(trim((string) $symbol));
            })
            ->filter(function ($symbol) {
                return $symbol !== '';
            })
            ->unique()
            ->values()
            ->all();

        $updateData = ['updated_at' => now()];

        if ($request->has('name')) {
            $name = trim((string) ($v['name'] ?? ''));
            if ($name === '') {
                return response()->json(['status' => 'error', 'message' => '类别名称不能为空'], 422);
            }

            $exists = DB::table('asset_categories')->get()->first(function ($item) use ($name, $id) {
                $rawId = $item->_id ?? ($item->id ?? null);
                $itemId = '';
                if (is_object($rawId)) {
                    if (isset($rawId->{'$oid'})) {
                        $itemId = (string) $rawId->{'$oid'};
                    } elseif (method_exists($rawId, '__toString')) {
                        $itemId = (string) $rawId;
                    }
                } elseif ($rawId !== null) {
                    $itemId = (string) $rawId;
                }

                return $itemId !== (string) $id
                    && mb_strtolower(trim((string) ($item->name ?? ''))) === mb_strtolower($name);
            });

            if ($exists) {
                return response()->json(['status' => 'error', 'message' => '类别已存在'], 422);
            }

            $updateData['name'] = $name;
        }

        if ($request->has('symbols')) {
            $updateData['symbols'] = $symbols;
        }

        if ($request->has('target_pct')) {
            $updateData['target_pct'] = max(0, (float) ($v['target_pct'] ?? 0));
        }

        if ($request->has('symbol_targets')) {
            $updateData['symbol_targets'] = is_array($v['symbol_targets'] ?? null) ? $v['symbol_targets'] : [];
        }

        $updated = DB::table('asset_categories')
            ->where('_id', $id)
            ->orWhere('id', $id)
            ->update($updateData);

        if ($updated === 0) {
            return response()->json(['status' => 'error', 'message' => '类别不存在'], 404);
        }

        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        $category = DB::table('asset_categories')->where('_id', $id)->first();

        if (!$category) {
            return response()->json(['status' => 'error', 'message' => '类别不存在'], 404);
        }

        DB::table('asset_categories')->where('_id', $id)->delete();

        return response()->json(['status' => 'success']);
    }
}