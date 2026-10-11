@props(['amount', 'suffix' => ''])
{{ (int) $amount === 0 ? '無料' : number_format($amount).'円'.$suffix }}
