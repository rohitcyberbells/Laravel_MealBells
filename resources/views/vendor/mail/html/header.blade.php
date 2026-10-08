@props(['url'])
{{--
    A text wordmark rather than an image. A hosted logo is one more thing that
    can 404, and most clients block remote images by default - so the first
    thing a vendor sees at 11am would be a broken box where the sender's name
    should be.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" class="wordmark">
<span class="wordmark-mark">&#9679;</span>{!! $slot !!}
</a>
</td>
</tr>
