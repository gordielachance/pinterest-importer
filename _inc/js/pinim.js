jQuery(document).ready(function($){
    
    //default hide category selector
    $('#the-list .column-category input[type="radio"]').click(function(e) {
        var cell = $( this ).parents('.column-category');
        var auto = cell.find('input[type="radio"][value="auto"]');
        var select = cell.find('select');
        if (auto.is(':checked')){
            select.addClass('hidden');
        }else{
            select.removeClass('hidden');
        }
    });
    /*
    //set color for pins cached count
    $("[data-cached-pc]").each(function(){
        var cell = $( this ).parents('.column-category');
        var pc = $( this ).data('cached-pc');
        if (pc<=0) return;
            var opacity = pc/100;
            $(this).css('background-color', 'rgba(0, 0, 0, '+opacity+')');
    });
    */
    
    //show or hide feedback
    $('#import_pins').click(function(e) {
        
        e.preventDefault();
        var link = $(this);
        var form = $('#pinim-form');
        var ajax_data = {};
        

        if(link.hasClass('loading')) return false;

        //ajax_data._wpnonce=getURLParameter(link.attr('href'),'_wpnonce');
        ajax_data.action='boards_import_pins';

        $.ajax({
    
            type: "post",
            url: ajaxurl,
            data:ajax_data,
            dataType: 'json',
            beforeSend: function() {
                //link.removeClass('.bbpvotes-db-loading .bbpvotes-db-success .bbpvotes-db-error');
                form.addClass('loading');
            },
            success: function(data){
                if (data.success == false) {
                    console.log(data.message);
                }else if (data.success == true) {
                    alert("success");
                    
                }
                console.log(data);
            },
            error: function (xhr, ajaxOptions, thrownError) {
                console.log(xhr.status);
                console.log(thrownError);
            },
            complete: function() {
                form.removeClass('loading');
            }
        });

    });

    
    
});

