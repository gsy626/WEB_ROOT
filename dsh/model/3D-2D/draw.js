/** @type {HTMLCanvasElement} */
const canvas=document.getElementById("canvas");
const ctx= canvas.getContext("2d");
const H=canvas.height;
const W=canvas.width;
const PointsOrigin=Array();
let angle=0;
//===================================================
const LengthObjectSource=200;
const LengthImageSource=800;
const D=10;
const SideLength=60;
const DeltaAngle= Math.PI / 400;
const Points=[
	{x:0,y:0,z:0},
	{x:SideLength,y:SideLength,z:SideLength},
	{x:-SideLength,y:SideLength,z:SideLength},
	{x:-SideLength,y:-SideLength,z:SideLength},
	{x:SideLength,y:-SideLength,z:SideLength},
	{x:SideLength,y:SideLength,z:-SideLength},
	{x:-SideLength,y:SideLength,z:-SideLength},
	{x:-SideLength,y:-SideLength,z:-SideLength},
	{x:SideLength,y:-SideLength,z:-SideLength}
];
const Paths=[
	[1,2,3,4],
	[5,6,7,8],
	[1,5],
	[2,6],
	[3,7],
	[4,8]
];
function main()
{
	Clear_all();
	angle+=DeltaAngle;
	var Cos=Math.cos(angle);
	var Sin=Math.sin(angle);
	ctx.fillStyle="green";
	for(let i=0;i<Points.length;i++)
	{
		let P=Transform_point(Points[i], Cos, Sin);
		PointsOrigin[i]={x:P.x,y:P.y};
	//	ctx.fillRect(P.x,P.y,D,D);
	}
	Draw_lines(Paths);
}
setInterval(() => main(),100);
//===============================================================
function Draw_lines(Paths)
{
	for(let i=0;i<Paths.length;i++)
		Draw_a_line(Paths[i]);
}
function Draw_a_line(path)
{
	ctx.strokeStyle="green";
	ctx.lineWidth=5;
	ctx.beginPath();
	ctx.moveTo(PointsOrigin[path[0]].x,PointsOrigin[path[0]].y);
	for(let i = 1; i<path.length;i++)
	ctx.lineTo(PointsOrigin[path[i]].x,PointsOrigin[path[i]].y);
	ctx.closePath();
	ctx.stroke();
}
function Clear_all()
{
	ctx.fillStyle="black";
	ctx.fillRect(0, 0, W, H);
}
function Linear_transform({x:x1,y:y1,z:z1})
{
	//return({x:(x1 - D/2 +W/2),y:( -y1 - D/2 + H/2),z:z1});点准确
	return({x:(x1 +W/2),y:( -y1  + H/2),z:z1});//线准确
}
function Rotate_transform({x:x1,y:y1,z:z1},Cos,Sin)
{
	var z2=((z1 * Cos - x1 * Sin) + LengthObjectSource );
	var x2=(x1 * Cos + z1 * Sin) * LengthImageSource /z2;
	var y2=y1 * LengthImageSource /z2;
	return({x:x2,y:y2,z:z2});
}
function Transform_point(point,Cos,Sin)
{
	return(	Linear_transform(Rotate_transform(point,Cos,Sin)));
}
