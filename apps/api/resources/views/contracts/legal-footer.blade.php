<footer class="footer">
<div class="footer-legal"><strong><bdi>{{ $agency['name'] }}</bdi></strong></div>
@if(!empty($agency['address']))
<div class="footer-legal"><bdi>{{ $agency['address'] }}</bdi></div>
@endif
@if(!empty($agency['phone']) || !empty($agency['email']))
<div class="footer-legal">
@if(!empty($agency['phone']))
{{ $ar?'الهاتف':'Tél.' }} : <bdi>{{ $agency['phone'] }}</bdi>
@endif
@if(!empty($agency['email']))
 · Email : <bdi>{{ $agency['email'] }}</bdi>@if(!empty($agency['email_example'])) ({{ $ar?'مثال':'exemple' }}) @endif
@endif
</div>
@endif
<div class="footer-legal">
@if(!empty($agency['capital']))
{{ $ar?'رأس المال':'Capital' }} : <bdi>{{ $agency['capital'] }}</bdi> ·
@endif
@foreach(['rc'=>'RC','ice'=>'ICE','if'=>'IF','tp'=>'TP'] as $key=>$label)
@if(!empty($agency[$key]))
<bdi>{{ $label }} {{ $agency[$key] }}</bdi>@if($key!=='tp') · @endif
@endif
@endforeach
</div>
<div class="footer-meta"><span>{{ $ar?'عقد كراء سيارة':'Contrat de location de véhicule' }}</span><span class="ref">{{ $reference }} | {{ $pageNumber }}/2</span></div>
</footer>
