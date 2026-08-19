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
  `id_nota` int NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;
