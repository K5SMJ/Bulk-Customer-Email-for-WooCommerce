jQuery(function($){
    let frame;

    $('#pmbulk_select_logo').on('click', function(e){
        e.preventDefault();

        if (frame) {
            frame.open();
            return;
        }

        frame = wp.media({
            title: 'Select Email Logo',
            button: { text: 'Use Logo' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $('#pmbulk_logo_id').val(attachment.id);
            $('#pmbulk_logo_preview').html(
                '<img src="' + attachment.url + '" alt="" class="pmbulk-logo-preview">'
            );
            $('#pmbulk_remove_logo').show();
        });

        frame.open();
    });

    $('#pmbulk_remove_logo').on('click', function(){
        $('#pmbulk_logo_id').val('0');
        $('#pmbulk_logo_preview').empty();
        $(this).hide();
    });
});
