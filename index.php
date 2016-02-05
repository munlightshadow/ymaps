<!DOCTYPE html>
<html>
    <head>
    <meta charset="UTF-8">
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
    <script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU" type="text/javascript"></script>
    <script type="text/javascript" src="simplify.js"></script>
  
    <style type="text/css">
        .mdl-button {
            padding: 5px;
            top: 10px; 
            left: 10px;
            background-color: green;
        }
        .mdl-button.selected {
            background-color: red!important;
        }
        #wrapper-map{
            width: 100%; 
            height: 400px;
        }        
    </style>

</head>
<body>
    <div id="wrapper-map" style="position: relative;">
        <div id="select_button" class="mdl-button mdl-js-button" style="z-index: 2000; position: absolute;" data-flag="fl">Выделить</div>
        <canvas id="canv" style="z-index: 1000; top: 0; left: 0; position: absolute; display:none;"></canvas>
        <div id="map" style="width: 100%; height: 100%; overflow: hidden; padding: 0; margin: 0; position: absolute;"></div>
    </div>
</body>


<script>
    function addItemPoints()
    {
        var ar_points = [];
            ar_points.push([0, 55.75, 37.60, 'text1']);
            ar_points.push([1, 55.75, 37.62, 'text2']);
            ar_points.push([2, 55.75, 37.60, 'text3']);
            ar_points.push([3, 55.77, 37.60, 'text4']);
        return ar_points;
    }

    function updateItemList(points)
    {
        console.log(points);
        //запрос для изменения списка items
        // $.ajax({
        //     url: '{{ path("ajax_get_items") }}',
        //     type: 'post',
        //     data: {points : points},
        //     dataType: 'json',
        //     // async: false,
        //     success: function(data){
        //         $('#list_items').html(data.html);
        //     },
        //     error: function(){
        //     }
        // });
    }


    var myMap;
    var points = [];            // массив всех точек
    var polygon = null;         // выделенная область
    var points_select = [];     // точки попавшие в выделенную область

    $(document).ready(function(){
        // offset_x, offset_y - поправка на положение карты на странице (позволяют корректно расчитывать положенеи курсора)
        var offset_x = $('#wrapper-map').offset().left;
        var offset_y = $('#wrapper-map').offset().top;

        //инициалтзация карыт
        var Map = (function() {

            ymaps.load(init);
            function init () {
                    myMap = new ymaps.Map("map", {
                    center: [55.75, 37.60],
                    zoom: 11,
                    controls: []
                });

                //добавляем точки на карту (addItemPoints некая функция возвращающая массив точек)
                points = addItemPoints();
                //добавляем нулевой полигон
                addPolygon()
            }


            function convert(coords) {
                var projection = myMap.options.get('projection');

                return coords.map(function(el) {
                    var c = projection.fromGlobalPixels(myMap.converter.pageToGlobal([el.x, el.y]), myMap.getZoom());
                    return c;
                });
            }

            //добавление выделенной области на карту
            function addPolygon(coord) {
                var myGeoObject = new ymaps.GeoObject({
                    geometry: {
                        type: "Polygon",
                        coordinates: [coord],
                    },
                }, {
                    fillColor: '#00FF00',
                    strokeColor: '#0000FF',
                    opacity: 0.5,
                    strokeWidth: 3
                });

                myMap.geoObjects.add(myGeoObject);

                return myGeoObject;
            }

            return {
                addPolygon: addPolygon,
                convert: convert            
            };
        })();

        //----------------------

        //реализация отключения выделения облости для предоставление возможности управлять картой
        $('#select_button').click(function(){
            if ($('#select_button').attr('data-flag') == 'fl')
            {
                $('#select_button').attr('data-flag', 'tr');
                $('#canv').show();
                $('#select_button').addClass('selected');
            } else
            {
                $('#select_button').attr('data-flag', 'fl');
                $('#canv').hide();
                $('#select_button').removeClass('selected');
            }
        })

        // получение элемента канвы
        var canv = document.getElementById('canv'),
            ctx = canv.getContext('2d'),

            line = [];

        // получение элемента карты
        var map = document.getElementById('map');
        
        canv.width = map.offsetWidth;
        canv.height = map.offsetHeight;

        var startX = 0,
            startY = 0;

        // обработка начала выделения обласи (по нажатию мыши)
        function mouseDown(e) {
            ctx.clearRect(0, 0, canv.width, canv.height);

            startX = e.pageX - e.target.offsetLeft - offset_x;
            startY = e.pageY - e.target.offsetTop - offset_y;

            canv.addEventListener('mouseup', mouseUp);
            canv.addEventListener('mousemove', mouseMove);


            line = [];
            line.push({
                x: startX + offset_x,
                y: startY + offset_y
            });
        }

        // обработка выделения (по движении мыши)
        function mouseMove(e) {
            var x = e.pageX - e.target.offsetLeft - offset_x,
                y = e.pageY - e.target.offsetTop - offset_y;

            ctx.beginPath();
            ctx.moveTo(startX, startY);
            ctx.lineTo(x, y);
            ctx.stroke();

            startX = x;
            startY = y;
            line.push({
                x: x + offset_x,
                y: y + offset_y
            });
        }

        // обработка завершения выделения (по отпусканию кнопки мыши)
        function mouseUp() {
            // убираем нажатие кнопки выделения, тем самым позваляя управлть картой
            $('#canv').hide();
            $('#select_button').removeClass('selected');
            $('#select_button').attr('data-flag', 'fl');

            // убираем прослушку событий по действиям мыши
            canv.removeEventListener('mouseup', mouseUp);
            canv.removeEventListener('mousemove', mouseMove);

            // проводим апроксимацию нарисованной линии, а так же добавляем получившийся полигон на карту
            aproximate();

            //расчет точек входящих в выделенный полигон
            var myCollection = new ymaps.GeoObjectCollection();
            myMap.geoObjects.remove(myCollection);
            var placemarks = [];
            myCollection = new ymaps.GeoObjectCollection();
            
            // пробегаем по всем точкам points и если они попадают в polygon.geometry.contains, то создаем
            // myPlacemark и кладем ее в myCollection. Так же весь список найденных точек кладем в points_select
            for (i in points) {
                if (polygon.geometry.contains([points[i][1], points[i][2]])){
                    points_select.push(points[i]);
                    
                    var myPlacemark = new ymaps.GeoObject({
                            geometry: {type: "Point", coordinates: [points[i][1], points[i][2]]},
                            properties: {
                                hintContent: points[i][3],
                                balloonContentBody: points[i][3]
                            }
                        });

                    placemarks.push(myPlacemark);
                    myCollection.add(myPlacemark);
                }
            }
            myMap.geoObjects.add(myCollection);

            /*updateItemList некая функция которая позволяет передать выбранные точки на сервер или обработать каким-либо образом
            допустим можно аяксом отправить на сервер выделенные точки, там отфильтровать по каким-лиюо дополнительным параметрам
            и вернуть отфильтрованный результат.*/
            updateItemList(points_select);

            //кластеризуем точки с одинаковыми координатами
            var clusterer = new ymaps.Clusterer();
            clusterer.options.set({
                groupByCoordinates: true
            });
            clusterer.add(placemarks);
            myMap.geoObjects.add(clusterer);

        }

        // функция апроксимирующая нарисованную линию в полигон и добавляющая ее на карту
        function aproximate() {
            ctx.clearRect(0, 0, canv.width, canv.height);
            var res = simplify(line, 5);
            res = Map.convert(res);
            polygon = Map.addPolygon(res);
        }

        canv.addEventListener('mousedown', mouseDown);
    });

</script>
</html>

