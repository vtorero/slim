CREATE TABLE `notacreditos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_usuario` varchar(10) NOT NULL,
  `serie_documento` varchar(15) DEFAULT NULL,
  `nro_documento` varchar(30) DEFAULT NULL,
  `id_compra` int DEFAULT NULL,
  `id_proveedor` int NOT NULL,
  `id_sucursal` int NOT NULL,
  `devolucion` tinyint DEFAULT NULL,
  `igv` decimal(10,2) DEFAULT '0.00',
  `monto_igv` decimal(10,2) DEFAULT '0.00',
  `valor_neto` decimal(10,2) DEFAULT '0.00',
  `descuento` decimal(10,2) DEFAULT '0.00',
  `valor_total` decimal(10,2) DEFAULT '0.00',
  `monto_pendiente` decimal(10,2) DEFAULT '0.00',
  `formaPago` varchar(20) DEFAULT NULL,
  `fechaPago` datetime DEFAULT NULL,
  `estado` varchar(30) DEFAULT '1' COMMENT '1=enviado\\n2=pendiente\\n3=anulado',
  `serie_comprobante` varchar(20) DEFAULT NULL,
  `nro_comprobante` varchar(100) DEFAULT NULL,
  `tipoDoc` varchar(50) NOT NULL,
  `observacion` text,
  `fecha` datetime NOT NULL,
  `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


CREATE TABLE `detalle_nota_credito_compra` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_nota_credito` int NOT NULL,
  `id_compra` int DEFAULT NULL,
  `id_producto` int NOT NULL,
  `id_inventario` int NOT NULL,
  `codigo` varchar(45) DEFAULT NULL,
  `unidad_medida` varchar(5) NOT NULL,
  `cantidad` decimal(10,4) NOT NULL,
  `precio` decimal(10,4) NOT NULL,
  `subtotal` decimal(10,4) NOT NULL,
  `fecha_registro` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_producto_compra_idx` (`id_producto`),
  CONSTRAINT `fk_producto_id_notacompra` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=latin1;

DELIMITER $$
CREATE DEFINER=`root`@`localhost` PROCEDURE `p_nota_credito_compra`(
IN `p_id_compra` INT(11),
IN `p_proveedor` INT(11),
IN `p_motivo` VARCHAR(45),
IN `p_sucursal` INT(11),
IN `p_total` DECIMAL(10,2),
IN `p_fecha` varchar(20),
IN `p_tipo_doc` VARCHAR(50),
 IN `p_observacion` TEXT,
IN `p_usuario` VARCHAR(255))
BEGIN
INSERT INTO `erp`.`notacreditos`
(`id_compra`,`id_proveedor`,`motivo`,`id_sucursal`,`valor_neto`,`fechaPago`,`estado`,
`tipoDoc`,`observacion`,`fecha`,`id_usuario`)
VALUES (p_id_compra,p_proveedor,p_motivo,p_sucursal,p_total,now(),'activo',p_tipo_doc,p_observacion,now(),p_usuario);
	END$$
DELIMITER ;


DELIMITER $$
CREATE DEFINER=`root`@`localhost` PROCEDURE `p_nota_credito_detalle`(
IN `p_id_nota` INT,
IN `p_id_producto` INT,
IN `p_id_compra` INT,
 IN `p_id_inventario` INT,
 IN `p_codigo` varchar(50),
 IN `p_unidad` VARCHAR(5),
 IN `p_cantidad` DECIMAL(10,2),
 IN `p_pendiente` DECIMAL(10,2),
 IN `p_descuento` DECIMAL(10,2),
 IN `p_precio` DECIMAL(10,2))
BEGIN
INSERT INTO detalle_nota_credito_compra (id_nota_credito,id_compra,id_producto,id_inventario,codigo,unidad_medida,cantidad,
precio,subtotal)
VALUES(p_id_nota,p_id_compra,p_id_producto,p_id_inventario,p_codigo,p_unidad,p_cantidad,p_precio,(p_precio*p_cantidad));
END$$
DELIMITER ;


/*detalle nota*/

SELECT
    dc.id,
    dc.id_producto,
	nombre,
    dc.codigo,
    dc.cantidad,
    dc.precio,

    IFNULL(nc.devuelto,0) AS devuelto,

    dc.cantidad - IFNULL(nc.devuelto,0) AS pendiente

FROM compra_detalle dc

LEFT JOIN
(
    SELECT
        nc.id_compra,
        dnc.id_producto,
        pr.nombre,
        SUM(dnc.cantidad) AS devuelto
    FROM notacreditos nc
    INNER JOIN detalle_nota_credito_compra dnc
        ON dnc.id_nota_credito = nc.id
	INNER JOIN productos pr ON pr.id=dnc.id_producto
    GROUP BY
        nc.id_compra,
        dnc.id_producto,
        pr.nombre
) nc
ON dc.id_compra = nc.id_compra
AND dc.id_producto = nc.id_producto

WHERE dc.id_compra = 271;

/*nuevos tipos de documento*/

CREATE TABLE tipo_documento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(2) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    serie VARCHAR(10) NOT NULL,
    correlativo INT NOT NULL DEFAULT 1,
    afecta_stock TINYINT(1) NOT NULL DEFAULT 0,
    afecta_caja TINYINT(1) NOT NULL DEFAULT 1,
    activo TINYINT(1) NOT NULL DEFAULT 1
);

INSERT INTO tipo_documento
(codigo, nombre, serie, correlativo, afecta_stock, afecta_caja, activo)
VALUES

('01','FACTURA','F001',1,1,1,1),

('03','BOLETA DE VENTA','B001',1,1,1,1),

('02','RECIBO POR HONORARIOS','RH001',1,0,1,1),

('14','RECIBO POR SERVICIOS','RS001',1,0,1,1),

('07','NOTA DE CRÉDITO','FC001',1,1,1,1),

('08','NOTA DE DÉBITO','FD001',1,0,1,1),

('30','OTROS DOCUMENTOS AUTORIZADOS','OD001',1,0,1,1),

('40','DEVOLUCIÓN','DV001',1,1,1,1),

('00','TICKET INTERNO','T001',1,1,1,1);


ALTER TABLE tipo_documento
ADD COLUMN tipo ENUM('VENTA','COMPRA','AJUSTE','TESORERIA') NOT NULL DEFAULT 'VENTA';




UPDATE tipo_documento SET tipo='VENTA' WHERE codigo IN ('00','01','03');
UPDATE tipo_documento SET tipo='AJUSTE' WHERE codigo IN ('07','08','40');
UPDATE tipo_documento SET tipo='TESORERIA' WHERE codigo IN ('02','14','30');


ALTER TABLE tipo_documento
MODIFY correlativo INT NOT NULL DEFAULT 0;

ALTER TABLE tipo_documento
ADD UNIQUE KEY uk_tipo_documento_codigo (codigo);