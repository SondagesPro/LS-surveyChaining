$(document).on('ready pjax:scriptcomplete', function(){
    $('.ajax-surveychaining').on('click',function(e){
        e.preventDefault();
        //console.log($(this).attr("href"));
        LS.ajax({
            url  : $(this).attr("href")
        });
        //~ notifyFader("toto",'well-lg bg-primary text-center');
    });
});
