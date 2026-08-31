<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заказы: оплачено, но не выдано</title>
    <style>
        body { font: 14px/1.5 system-ui, sans-serif; margin: 0; padding: 24px; background: #f4f4f5; color: #18181b; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #71717a; }
        .card { background: #fff; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #71717a; }
        code { background: #f4f4f5; border-radius: 4px; padding: 1px 5px; font-size: 13px; }
        button { background: #18181b; color: #fff; border: 0; border-radius: 8px; padding: 7px 12px; cursor: pointer; font: inherit; }
        button.secondary { background: #e4e4e7; color: #18181b; }
        .status { background: #dcfce7; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .pill { border-radius: 999px; padding: 2px 10px; font-size: 12px; background: #fef3c7; }
    </style>
</head>
<body>
    <h1>Оплачено, но не выдано</h1>
    <p class="muted">Повторная выдача идемпотентна: сколько раз ни нажми, ключ уйдёт один.</p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="row">
            @foreach ($inventories as $id => $inventory)
                <div>
                    <strong>Поставщик {{ strtoupper((string) $id) }}</strong>
                    @if (isset($inventory['error']))
                        <span class="pill">недоступен</span>
                    @else
                        <span class="muted">
                            свободно {{ $inventory['free'] ?? '?' }} из {{ $inventory['total'] ?? '?' }},
                            выдано {{ $inventory['issued'] ?? '?' }}
                        </span>
                    @endif
                    <form method="post" action="{{ route('admin.restock', ['supplier' => $id]) }}?token={{ $token }}" style="display:inline">
                        @csrf
                        <input type="hidden" name="count" value="5">
                        <button class="secondary" type="submit">Пополнить на 5</button>
                    </form>
                </div>
            @endforeach
            <div class="muted">Всего выдано заказов: {{ $deliveredCount }}</div>
        </div>
    </div>

    <div class="card">
        @if ($orders->isEmpty())
            <p class="muted">Незавершённых заказов нет.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Заказ</th>
                        <th>Товар</th>
                        <th>Статус</th>
                        <th>Выдача</th>
                        <th>Последняя ошибка</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><code>{{ $order->id }}</code><br><span class="muted">{{ $order->created_at?->diffForHumans() }}</span></td>
                            <td>{{ $order->product?->name ?? $order->sku }}<br><span class="muted">{{ number_format($order->total_minor / 100, 2, ',', ' ') }} {{ $order->currency }}</span></td>
                            <td>{{ $order->status->label() }}<br><code>{{ $order->status->value }}</code></td>
                            <td>
                                @if ($order->delivery)
                                    <code>{{ $order->delivery->state->value }}</code><br>
                                    <span class="muted">попыток: {{ $order->delivery->attempts }}</span><br>
                                    <span class="muted">{{ $order->delivery->request_id }}</span>
                                @else
                                    <span class="muted">нет записи</span>
                                @endif
                            </td>
                            <td class="muted">{{ $order->delivery?->last_error }}</td>
                            <td>
                                <form method="post" action="{{ route('admin.redeliver', ['order' => $order->id]) }}?token={{ $token }}">
                                    @csrf
                                    <button type="submit">Выдать повторно</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
