jQuery(function ($) {
    let ids = [];
    let index = 0;

    $('#start-compare').on('click', function () {
        $('#results').empty();
        $('#progress-wrap').show();

        const types = [];
        $('input[type=checkbox]').each(function () {
            if (this.checked && this.value) types.push(this.value);
        });

        $.post(ajaxurl, {
            action: 'query_posts',
            types: types
        });

        $.getJSON(SEOCompare.ajax, {
                action: 'seo_meta_compare_get_ids',
                types: types
            }, function (data) {
                ids = data;
                run();
            }
        );
    });

    
    $('#export-csv').on('click', function () {
        window.location =
            SEOCompare.ajax +
            '?action=seo_meta_compare_export';
    });


    function run() {
        if (index >= ids.length) return;

        $.post(SEOCompare.ajax, {
            action: 'seo_meta_compare_run',
            nonce: SEOCompare.nonce,
            post_id: ids[index],
            original: $('#original').val(),
            target: $('#target').val(),
            normalize: $('#normalize').is(':checked') ? 1 : 0,
            canonical_only: $('#canonical_only').is(':checked') ? 1 : 0
        }, function (res) {

            $('#results').append(
                `<tr>
                    <td>${ids[index]}</td>
                    <td>${res.url}</td>
                    <td>${res.status}</td>
                    <td>${res.details}</td>
                </tr>`
            );

            index++;
            $('#progress').val((index / ids.length) * 100);
            $('#progress-text').text(index + ' / ' + ids.length);
            run();

            if (index >= ids.length) {
                $('#export-csv').prop('disabled', false);
            }
        });
    }
});
