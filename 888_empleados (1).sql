-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Servidor: localhost:3306
-- Tiempo de generación: 28-01-2026 a las 22:20:38
-- Versión del servidor: 10.11.14-MariaDB-ubu2204-log
-- Versión de PHP: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `agrocosta_empleados`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `solicitudes`
--

CREATE TABLE `solicitudes` (
  `id` int(11) NOT NULL,
  `empleado` varchar(100) DEFAULT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `cargo` varchar(100) DEFAULT NULL,
  `correo_jefe` varchar(100) DEFAULT NULL,
  `motivo` varchar(50) DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'Pendiente',
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `observacion_jefe` text DEFAULT NULL,
  `archivo_soporte` text DEFAULT NULL,
  `ip_aprobacion` varchar(50) DEFAULT NULL,
  `info_dispositivo` varchar(255) DEFAULT NULL,
  `fecha_gestion` datetime DEFAULT NULL,
  `usuario_gestor` varchar(100) DEFAULT NULL,
  `fecha_solicitud` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `solicitudes`
--

INSERT INTO `solicitudes` (`id`, `empleado`, `cedula`, `cargo`, `correo_jefe`, `motivo`, `estado`, `fecha`, `fecha_inicio`, `fecha_fin`, `hora_inicio`, `hora_fin`, `notas`, `observacion_jefe`, `archivo_soporte`, `ip_aprobacion`, `info_dispositivo`, `fecha_gestion`, `usuario_gestor`, `fecha_solicitud`) VALUES
(18, 'tttt tttt', '6668688877', 'Soporte técnico en sistemas', 'uuuuu@8888-88888.com', 'Cita Médica', 'Aprobado', '2026-01-28 16:48:33', '2026-01-30', '2026-01-30', '14:00:00', '18:00:00', '', '', '1769618913_soporte.jpeg', NULL, NULL, NULL, NULL, '2026-01-28 21:02:30');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nombre_completo` varchar(100) DEFAULT NULL,
  `rol` varchar(20) DEFAULT NULL,
  `correo` varchar(100) DEFAULT NULL,
  `cedula` varchar(20) DEFAULT '',
  `cargo` varchar(100) DEFAULT '',
  `area` varchar(100) DEFAULT '',
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_expire` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `username`, `password`, `nombre_completo`, `rol`, `correo`, `cedula`, `cargo`, `area`, `reset_token`, `reset_expire`) VALUES
(1, 'ttttt', '', 'RRHH ttt-ttt', 'admin', 'ttt@jjjj-7777.com', '000000', 'Jefe RRHH', 'Administración', NULL, NULL),
(2, 'tttt@tt-tt.com', '', 'eeee eeeee', 'jefe', 'aaaaaa@aaaa-aaaa.com', '11111', 'Jefe de Sistemas', 'DPTO. DE SISTEMAS', NULL, NULL),
(4, 'admin', '$2y$10$FGJ9bzOhukbnsJjBE3dzdOzrmKy9/JEqdg1Okr1THEg76SzXVEM76', 'admin', 'admin', 'sss@gmail.com', '0000001', 'admin', 'admin', NULL, NULL),
(5, 'pppppp', '$2y$10$kI52nSMuOspqpgb0l4SaEO82jQ9eXa3zlEtDm2JF.1eo0Rbb3EDPu', 'aaaa aaaaa', 'empleado', 'ss@ss-ss.com', '9799878', 'Soporte técnico en sistemas', 'DPTO. DE SISTEMAS', NULL, NULL),
(9, 'demo', '$2y$10$e9EjIWl.Kom0iDR6hByUvO9K59MsH8JJEHlz.2ESWEJ3BXiuLQhVi', 'empleado demo', 'empleado', 'ss@gmail.com', '1234', 'indiferente', 'DPTO. DE ', NULL, NULL),
(10, 'jefedemo', '$2y$10$Ng0S5nmgKhoFywiSFv.Ca.oKC91ryH7dCqaj9ECq0/gNfCqvmEUzu', 'jefe demo', 'jefe', 'sssss@gmail.com', '56789', 'Jefe de', 'DPTO. DE ', NULL, NULL);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `solicitudes`
--
ALTER TABLE `solicitudes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
