<?php
declare(strict_types=1);

namespace Modules\Category\Service;

/**
 * 商品类型（立创类目）中英映射
 *
 * 立创商品详情接口返回的 catalogName 是英文（如 "RF Transceiver ICs"），
 * 直接当中文分类用不了。这里按**关键词包含匹配**把它翻成中文类目，
 * 命中不了就保留英文原文（不丢信息）。
 *
 * 匹配规则：关键词表按长度降序尝试，先命中先算；
 * 「关键词在类目名中」即算命中（如 "Diode" 可命中 "TVS Diodes"）。
 */
final class ProductTypeMap
{
    /** 关键词 → 中文类目（长关键词优先匹配） */
    private const RULES = [
        // ── 无源器件 ──
        'Chip Resistor'              => '贴片电阻',
        'Resistor Networks'          => '电阻网络',
        'Resistor'                   => '电阻',
        'Capacitor'                  => '电容',
        'Ceramic Capacitor'          => '陶瓷电容',
        'Tantalum Capacitor'         => '钽电容',
        'Aluminum Electrolytic'      => '铝电解电容',
        'Inductor'                   => '电感',
        'Ferrite Bead'               => '磁珠',
        'Transformer'                => '变压器',
        'Crystal'                    => '晶振',
        'Crystal Resonator'          => '谐振器',
        'Oscillator'                 => '振荡器',
        // ── 半导体 ──
        'MOSFET'                     => 'MOSFET',
        'IGBT'                       => 'IGBT',
        'BJT'                        => '三极管',
        'Transistor'                 => '三极管',
        'Diode'                      => '二极管',
        'Zener'                      => '稳压二极管',
        'TVS'                        => 'TVS管',
        'Rectifier'                  => '整流器',
        'LED'                        => 'LED',
        'Optocoupler'                => '光耦',
        'Phototransistor'            => '光敏晶体管',
        'Sensor'                     => '传感器',
        'Humidity'                   => '湿度传感器',
        'Temperature Sensor'         => '温度传感器',
        'Hall Sensor'                => '霍尔传感器',
        'Accelerometer'              => '加速度计',
        'Gyroscope'                  => '陀螺仪',
        'Op Amps'                    => '运算放大器',
        'Buffer Amps'                => '缓冲放大器',
        'Instrumentation'             => '仪表放大器',
        'Reference Voltage'          => '基准电压源',
        'Regulator'                  => '稳压器',
        'Voltage Regulator'          => '稳压器',
        'DC DC'                      => 'DC-DC电源',
        'LDO'                        => '低压差稳压器',
        'Real Time Clock'            => '实时时钟',
        'RTC'                        => '实时时钟',
        'Memory'                     => '存储器',
        'EEPROM'                     => 'EEPROM',
        'Flash'                      => '闪存',
        'RAM'                        => 'RAM',
        'Microcontroller'            => '单片机',
        'MCU'                        => '单片机',
        'CPU'                        => 'CPU',
        'FPGA'                       => 'FPGA',
        'Logic Gate'                 => '逻辑门',
        'Comparator'                 => '比较器',
        'Timer'                      => '定时器',
        'Interface'                  => '接口芯片',
        'Transceiver'                => '收发器',
        'RF Transceiver ICs'         => '射频收发器IC',
        'RF Transceiver Modules'     => '射频收发模块',
        'Modems'                     => '调制解调器',
        'RF and Wireless'            => '射频与无线',
        'Amplifier'                  => '放大器',
        'Switch'                     => '开关',
        'Analog Switch'              => '模拟开关',
        'Multiplexer'                => '多路复用器',
        'Digital'                    => '数字电路',
        'Analog'                     => '模拟电路',
        'Output'                     => '输出模块',
        'Input'                      => '输入模块',
        'Gate Driver'                => '栅极驱动',
        'Motor'                      => '电机驱动',
        'Driver'                     => '驱动器',
        'PMIC'                       => '电源管理IC',
        'Power'                      => '电源',
        // ── 连接器 ──
        'Header'                     => '排针',
        'Socket'                     => '插座',
        'Connector'                  => '连接器',
        'Terminal'                   => '接线端子',
        'USB'                        => 'USB连接器',
        'RJ45'                       => 'RJ45连接器',
        'Adapter'                    => '适配器',
        'PCB'                        => 'PCB板',
        'Module'                     => '模块',
        'Fuse'                       => '保险丝',
        'Relay'                      => '继电器',
        'Switcher'                   => '开关电源',
        'Transformer Module'         => '电源模块',
        'Antenna'                    => '天线',
        'Cable'                      => '线缆',
        'Housing'                    => '外壳',
        'Heat Sink'                  => '散热器',
        'I/O Expander'               => 'IO扩展器',
        'Potentiometer'              => '电位器',
        'Trimmer'                    => '微调电位器',
        'Varistor'                   => '压敏电阻',
        'Photoresistor'              => '光敏电阻',
        'Thermistor'                 => '热敏电阻',
        'Temperature Sensor'         => '温度传感器',
        'Temp Sensor'                => '温度传感器',
        'Humidity Sensor'            => '湿度传感器',
        'Opto'                       => '光电器件',
        'Optoelectronic'             => '光电器件',
        'Photovoltaic'               => '光伏电池',
        'Backlight'                  => '背光',
        'Encoder'                    => '编码器',
        'Tactile Switch'             => '轻触开关',
        'Slide Switch'               => '拨动开关',
        'Rocker Switch'              => '摇臂开关',
        'Lamp'                       => '指示灯',
        'Voltage Detector'           => '电压检测',
        'Voltage Reference'          => '基准电压源',
        'Current Sensor'             => '电流传感器',
        'Load Switch'                => '负载开关',
        'Relay Module'               => '继电器模块',
        'Power Module'               => '电源模块',
        'DC DC Converter'            => 'DC-DC转换器',
        'Isolated'                   => '隔离模块',
        'SSR'                        => '固态继电器',
        'Fuse'                       => '保险丝',
        'PTC'                        => 'PTC热敏电阻',
        'NTC'                        => 'NTC热敏电阻',
        'Aluminum Electrolytic'      => '铝电解电容',
        'Tantalum Capacitor'         => '钽电容',
        'Ceramic Capacitor'          => '陶瓷电容',
        'Film Capacitor'             => '薄膜电容',
        'Super Capacitor'            => '超级电容',
        'Aluminum Housing'           => '铝壳电容',
        'Resistor Array'             => '排阻',
        'Current Sense Resistor'     => '采样电阻',
        'Fuse Resistor'              => '熔断电阻',
        'Light Dependent'            => '光敏电阻',
        'Wireless'                   => '无线模块',
        'Antenna'                    => '天线',
        'Battery'                    => '电池',
        'Connector Header'           => '排针',
        'Board Connector'            => '板对板连接器',
        'FFC'                        => 'FFC连接器',
        'Terminal Block'             => '接线端子',
    ];

    /** 缓存：英文 → 中文 */
    private static array $memo = [];

    /**
     * 英文立创类目 → 中文类目（未命中则原样返回）
     */
    public static function translate(string $type): string
    {
        $type = trim($type);
        if ($type === '') return '';
        if (isset(self::$memo[$type])) return self::$memo[$type];

        $result = $type;
        foreach (self::RULES as $kw => $zh) {
            if ($kw !== '' && stripos($type, $kw) !== false) {
                $result = $zh;
                break;
            }
        }
        self::$memo[$type] = $result;
        return $result;
    }
}
