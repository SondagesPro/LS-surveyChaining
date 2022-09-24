$(document).on('ready pjax:scriptcomplete', function(){
    $('.ajax-surveychaining').on('click',function(e){
        var link = $(this);
        var helpblock = $(this).closest('.help-block');
        e.preventDefault();
        $.ajax({
            url  : $(this).attr("href"),
            success: function(result){
                if(result.success) {
                    $(helpblock).append('<p class="text-success">' + result.success + "</p>");
                    $(link).remove();
                } else {
                    if(result.error.message) {
                        $(helpblock).append('<p class="text-danger">' + result.error.message + "</p>");
                    }
                }
            },
            error: function(xhr, status, error){
                var errorMessage = xhr.status + ': ' + xhr.statusText
                $(helpblock).append('<div class="alert alert-danger">' + errorMessage + "</div>");
            }
        });
    });
});
