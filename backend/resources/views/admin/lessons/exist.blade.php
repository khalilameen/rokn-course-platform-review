@extends('admin.layouts.app')

@section('page.title', 'المتاجر')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
              <div class="row admin-learning admin-learning--map">
				  <div class="col-md-12">
				  <div class="card admin-card">
                      <div class="card-header"><i class="fa fa-map"></i><strong class="card-title pr-2">المتواجدين الان</strong></div>
                      <div class="card-body online-map">
<div id="map"></div>

    <script>
      var customLabel = {
        restaurant: {
          label: 'R'
        },
        bar: {
          label: 'B'
        }
      };

        function initMap() {

        var map = new google.maps.Map(document.getElementById('map'), {
          center: new google.maps.LatLng(23.8859, 45.0792),
          zoom: 6
        });
        var infoWindow = new google.maps.InfoWindow;

          // Change this depending on the name of your PHP or XML file
        //  downloadUrl('https://storage.googleapis.com/mapsdevsite/json/mapmarkers2.xml', function(data) {
          	var data = '{!! $storesXML !!}';
          	var parser = new DOMParser();
			var xml = parser.parseFromString(data,"text/xml");
          //  var xml = data.responseXML;
            var markers = xml.documentElement.getElementsByTagName('marker');
            console.log(markers);
            Array.prototype.forEach.call(markers, function(markerElem) {

              var id = markerElem.getAttribute('id');
              var name = markerElem.getAttribute('name');
              var address = markerElem.getAttribute('address');
              var type = markerElem.getAttribute('type');
              var point = new google.maps.LatLng(
                  parseFloat(markerElem.getAttribute('lat')),
                  parseFloat(markerElem.getAttribute('lng')));

              var infowincontent = document.createElement('div');
              var strong = document.createElement('strong');
              strong.textContent = name
              infowincontent.appendChild(strong);
              infowincontent.appendChild(document.createElement('br'));

              var text = document.createElement('text');
              text.textContent = address
              infowincontent.appendChild(text);
              var icon = customLabel[type] || {};
              var marker = new google.maps.Marker({
                map: map,
                position: point,
                label: icon.label,
                icon: '/images/6.png'
              });
              marker.addListener('click', function() {
                infoWindow.setContent(infowincontent);
                infoWindow.open(map, marker);
              });
            });
        //  });
        }



      function downloadUrl(url, callback) {
        var request = window.ActiveXObject ?
            new ActiveXObject('Microsoft.XMLHTTP') :
            new XMLHttpRequest;

        request.onreadystatechange = function() {
          if (request.readyState == 4) {
            request.onreadystatechange = doNothing;
            callback(request, request.status);
          }
        };

        request.open('GET', url, true);
        request.send(null);
      }

      function doNothing() {}
      // Google Map is using JSONP.
// So we only need to detect the services removing access and disabling them by not
      // Store old reference
const appendChild = Element.prototype.appendChild;
// inserting them inside the DOM
const urlCatchers = [
  "/AuthenticationService.Authenticate?",
  "/QuotaService.RecordEvent?"
];
Element.prototype.appendChild = function (element) {
  const isGMapScript = element.tagName === 'SCRIPT' && /maps\.googleapis\.com/i.test(element.src);
  const isGMapAccessScript = isGMapScript && urlCatchers.some(url => element.src.includes(url));

  if (!isGMapAccessScript) {
    return appendChild.call(this, element);
  }

  // Extract the callback to call it with success data
  // Only needed if you actually want to use Autocomplete/SearchBox API
  //const callback = element.src.split(/.*callback=([^\&]+)/, 2).pop();
  //const [a, b] = callback.split('.');
  //window[a][b]([1, null, 0, null, null, [1]]);

  // Returns the element to be compliant with the appendChild API
  return element;
};

    </script>
    @if (filled(config('services.google.maps_browser_key')))
        <script defer
                src="https://maps.googleapis.com/maps/api/js?key={{ rawurlencode((string) config('services.google.maps_browser_key')) }}&callback=initMap">
        </script>
    @endif
                    <!--  <iframe src="https://www.google.com/maps/d/embed?mid=1B1yuFOhWyGbWI0Dl2SG5s_UVe7dBeJ3l&hl=ar"></iframe>-->
						  <div class="clearfix"></div>
						  <!--<div class="col-sm-4">
						  	<div class="small-boxs">
							  	<img src="images/1.png" />
								<h3>مندوب متصل</h3>
						  	</div>
						  </div>
						  <div class="col-sm-4">
						  	<div class="small-boxs">
							  	<img src="images/2.png" />
								<h3>مندوب غير متصل</h3>
						  	</div>
						  </div> -->
						  <div class="col-sm-4">
						  	<div class="small-boxs">
							  	<img src="/images/5.png" />
								<h3>متجر</h3>
						  	</div>
						  </div>
                      </div>
                    </div>
				  </div>
              </div>

@endsection

